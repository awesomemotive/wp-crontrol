<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\Log;

/**
 * Cron event logging, folded in from WP Crontrol Pro.
 */
final class LoggingTest extends Test {
	/**
	 * @var array<int, string>
	 */
	private array $observed = array();

	#[\Override]
	public function set_up() {
		parent::set_up();

		EventRunModel::truncate();
		delete_option( 'crontrol_log' );
	}

	#[\Override]
	public function tear_down() {
		foreach ( $this->observed as $hook ) {
			Log::get_instance()->unobserve( $hook );
		}

		remove_all_filters( 'crontrol/should_log' );
		EventRunModel::truncate();

		parent::tear_down();
	}

	private function observe( string $hook ): void {
		Log::get_instance()->observe( $hook );
		$this->observed[] = $hook;
	}

	/**
	 * @return array<int, EventRun>
	 */
	private function runs( string $hook ): array {
		return array_values(
			array_filter(
				EventRunModel::query()->per_page( 100 )->get(),
				static fn ( EventRun $run ): bool => $run->hook === $hook
			)
		);
	}

	public function testLoggingIsOffByDefault(): void {
		self::assertSame( array(), Log::get_enabled_hooks() );

		// A fresh install has no logging option at all, and nothing seeds one.
		\Crontrol\Pro\on_init();

		self::assertFalse( get_option( 'crontrol_log', false ) );
		self::assertSame( array(), Log::get_enabled_hooks() );
	}

	public function testRunsOutsideCronAreNotLogged(): void {
		$this->observe( 'crontrol_test_not_cron' );

		do_action( 'crontrol_test_not_cron' );

		self::assertSame( array(), $this->runs( 'crontrol_test_not_cron' ) );
	}

	public function testRunIsLoggedDuringCron(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_logged' );

		do_action( 'crontrol_test_logged', array( 'secret' => 'value' ) );

		$runs = $this->runs( 'crontrol_test_logged' );

		self::assertCount( 1, $runs );
		self::assertSame( EventRun::STATUS_COMPLETED, $runs[0]->status );
		// Event arguments are not stored by default.
		self::assertSame( array(), $runs[0]->args );
	}

	public function testWpMailFilterReturnsItsArguments(): void {
		$log  = Log::get_instance();
		$args = array(
			'to'      => 'someone@example.com',
			'subject' => 'Subject',
			'message' => 'Message',
		);

		self::assertSame( $args, $log->filter_wp_mail( $args ) );

		// During a logged run, other wp_mail filters must still receive the arguments.
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_mail' );

		$seen = null;
		add_action( 'crontrol_test_mail', static function () use ( $args, &$seen ): void {
			$seen = apply_filters( 'wp_mail', $args );
		} );

		do_action( 'crontrol_test_mail' );

		self::assertSame( $args, $seen );

		$runs = $this->runs( 'crontrol_test_mail' );
		self::assertCount( 1, $runs );
		self::assertSame( 1, $runs[0]->emails );
	}

	public function testStateDoesNotLeakBetweenRuns(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_first' );
		$this->observe( 'crontrol_test_second' );

		add_action( 'crontrol_test_first', static function (): void {
			apply_filters( 'wp_mail', array() );
			apply_filters( 'wp_mail', array() );
			do_action( 'crontrol/error', 'First run failed' );
		} );

		do_action( 'crontrol_test_first' );
		do_action( 'crontrol_test_second' );

		$first  = $this->runs( 'crontrol_test_first' );
		$second = $this->runs( 'crontrol_test_second' );

		self::assertCount( 1, $first );
		self::assertCount( 1, $second );
		self::assertSame( 2, $first[0]->emails );
		self::assertSame( EventRun::STATUS_ERRORED, $first[0]->status );
		self::assertSame( 0, $second[0]->emails );
		self::assertSame( array(), $second[0]->errors );
		self::assertSame( EventRun::STATUS_COMPLETED, $second[0]->status );

		// Nothing stays attached once no run is in progress.
		self::assertFalse( has_filter( 'wp_mail', array( Log::get_instance(), 'filter_wp_mail' ) ) );
	}

	public function testNestedRunsAreRecordedSeparately(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_outer' );
		$this->observe( 'crontrol_test_inner' );

		add_action( 'crontrol_test_outer', static function (): void {
			apply_filters( 'wp_mail', array() );
			do_action( 'crontrol_test_inner' );
			apply_filters( 'wp_mail', array() );
		} );

		add_action( 'crontrol_test_inner', static function (): void {
			apply_filters( 'wp_mail', array() );
			do_action( 'crontrol/warning', 'Inner warning' );
		} );

		do_action( 'crontrol_test_outer' );

		$outer = $this->runs( 'crontrol_test_outer' );
		$inner = $this->runs( 'crontrol_test_inner' );

		self::assertCount( 1, $outer );
		self::assertCount( 1, $inner );
		self::assertSame( 2, $outer[0]->emails );
		self::assertSame( 1, $inner[0]->emails );
		self::assertCount( 1, $inner[0]->errors );
		self::assertSame( array(), $outer[0]->errors );
		self::assertSame( EventRun::STATUS_COMPLETED, $outer[0]->status );
	}

	public function testLoggedURLsHaveNoQueryString(): void {
		self::assertSame( 'https://api.example.com/v1/items?[redacted]', Log::redact_url( 'https://user:pass@api.example.com/v1/items?key=secret#frag' ) );
		self::assertSame( 'http://example.com:8080/path', Log::redact_url( 'http://example.com:8080/path' ) );
		self::assertSame( '[redacted URL]', Log::redact_url( 'not a url' ) );
	}

	public function testPrunesOldEntries(): void {
		$old = EventRun::start( 'crontrol_test_prune', '', time(), array() );
		$old->started_at = new \DateTimeImmutable( '-40 days', new \DateTimeZone( 'UTC' ) );
		EventRunModel::create( $old );

		$new = EventRun::start( 'crontrol_test_prune', '', time(), array() );
		EventRunModel::create( $new );

		EventRunModel::prune_sites( 30 );

		self::assertCount( 1, $this->runs( 'crontrol_test_prune' ) );
	}
}
