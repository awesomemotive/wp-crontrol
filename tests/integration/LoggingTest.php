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

	public function testLoggedURLsKeepOnlyTheOrigin(): void {
		self::assertSame( 'https://api.example.com/[redacted]', Log::redact_url( 'https://user:pass@api.example.com/v1/items?key=secret#frag' ) );
		self::assertSame( 'http://example.com:8080/[redacted]', Log::redact_url( 'http://example.com:8080/webhooks/secret-token' ) );
		self::assertSame( 'https://example.com', Log::redact_url( 'https://example.com/' ) );
		self::assertSame( '[redacted URL]', Log::redact_url( 'not a url' ) );
	}

	public function testHTTPRequestsAreCountedWithoutSecrets(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_http' );

		add_action( 'crontrol_test_http', static function (): void {
			wp_remote_get( 'http://httpbin/status/500?token=very-secret' );
			wp_remote_get( 'http://httpbin/status/200' );
			wp_remote_get( 'http://httpbin/status/204' );
		} );

		do_action( 'crontrol_test_http' );

		$runs = $this->runs( 'crontrol_test_http' );

		self::assertCount( 1, $runs );
		self::assertSame( 3, $runs[0]->https );
		self::assertTrue( $runs[0]->warning );
		self::assertStringNotContainsString( 'very-secret', (string) wp_json_encode( array( $runs[0]->errors, $runs[0]->logs ) ) );
		self::assertStringNotContainsString( '/status/', (string) wp_json_encode( array( $runs[0]->errors, $runs[0]->logs ) ) );
	}

	public function testCaughtExceptionInNestedHookDoesNotCorruptTheStack(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_catch_outer' );
		$this->observe( 'crontrol_test_catch_inner' );

		add_action( 'crontrol_test_catch_outer', static function (): void {
			try {
				do_action( 'crontrol_test_catch_inner' );
			} catch ( \RuntimeException $e ) {
				// Caught by the code that fired the hook.
			}
		} );

		add_action( 'crontrol_test_catch_inner', array( self::class, 'throw_inner_failure' ) );

		$handler_before = set_exception_handler( null );
		set_exception_handler( $handler_before );

		do_action( 'crontrol_test_catch_outer' );

		// do_action() leaves the inner hook on WordPress's own filter stack after the exception.
		global $wp_current_filter;
		$wp_current_filter = array_values( array_diff( $wp_current_filter, array( 'crontrol_test_catch_inner' ) ) );

		$outer = $this->runs( 'crontrol_test_catch_outer' );
		$inner = $this->runs( 'crontrol_test_catch_inner' );

		self::assertCount( 1, $outer );
		self::assertCount( 1, $inner );
		self::assertSame( EventRun::STATUS_COMPLETED, $outer[0]->status );
		self::assertSame( EventRun::STATUS_ERRORED, $inner[0]->status );

		// Listeners and the exception handler are back to how they were.
		self::assertFalse( has_filter( 'wp_mail', array( Log::get_instance(), 'filter_wp_mail' ) ) );
		$handler_after = set_exception_handler( null );
		set_exception_handler( $handler_after );
		self::assertSame( $handler_before, $handler_after );

		// A later run is unaffected.
		do_action( 'crontrol_test_catch_outer' );
		$wp_current_filter = array_values( array_diff( $wp_current_filter, array( 'crontrol_test_catch_inner' ) ) );
		self::assertCount( 2, $this->runs( 'crontrol_test_catch_outer' ) );
	}

	public function testCaughtExceptionInRecursiveHookClosesTheRightRun(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_recursive' );

		$depth = 0;
		add_action( 'crontrol_test_recursive', static function () use ( &$depth ): void {
			$depth++;

			if ( 1 === $depth ) {
				try {
					do_action( 'crontrol_test_recursive' );
				} catch ( \RuntimeException $e ) {
					// Caught by the code that fired the hook.
				}
				return;
			}

			throw new \RuntimeException( 'Inner failure' );
		} );

		do_action( 'crontrol_test_recursive' );

		global $wp_current_filter;
		$wp_current_filter = array_values( array_diff( $wp_current_filter, array( 'crontrol_test_recursive' ) ) );

		$runs = $this->runs( 'crontrol_test_recursive' );
		$statuses = array_map( static fn ( EventRun $run ): string => $run->status, $runs );
		sort( $statuses );

		self::assertSame( array( EventRun::STATUS_COMPLETED, EventRun::STATUS_ERRORED ), $statuses );
		self::assertFalse( has_filter( 'wp_mail', array( Log::get_instance(), 'filter_wp_mail' ) ) );
	}

	public function testParentActivityAfterCatchingIsAttributedToTheParent(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_parent' );
		$this->observe( 'crontrol_test_child' );

		add_action( 'crontrol_test_parent', static function (): void {
			try {
				do_action( 'crontrol_test_child' );
			} catch ( \RuntimeException $e ) {
				do_action( 'crontrol/error', 'Parent saw the child fail' );
				apply_filters( 'wp_mail', array() );
			}
		} );
		add_action( 'crontrol_test_child', array( self::class, 'throw_inner_failure' ) );

		do_action( 'crontrol_test_parent' );

		global $wp_current_filter;
		$wp_current_filter = array_values( array_diff( $wp_current_filter, array( 'crontrol_test_child' ) ) );

		$parent = $this->runs( 'crontrol_test_parent' );
		$child = $this->runs( 'crontrol_test_child' );

		self::assertCount( 1, $parent );
		self::assertCount( 1, $child );
		self::assertSame( EventRun::STATUS_ERRORED, $parent[0]->status );
		self::assertSame( 1, $parent[0]->emails );
		self::assertSame( 'Parent saw the child fail', $parent[0]->errors[0]['message'] );
		self::assertSame( 0, $child[0]->emails );
		self::assertSame( EventRun::STATUS_ERRORED, $child[0]->status );
	}

	public function testMessagesAreBoundedWhileCollecting(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_chatty' );

		add_action( 'crontrol_test_chatty', static function (): void {
			for ( $i = 0; $i < 500; $i++ ) {
				do_action( 'crontrol/info', 'Message {i}', array(
					'i'     => $i,
					'large' => str_repeat( 'x', 10000 ),
				) );
			}
			do_action( 'crontrol/warning', 'A late warning' );
		} );

		do_action( 'crontrol_test_chatty' );

		$runs = $this->runs( 'crontrol_test_chatty' );

		self::assertCount( 1, $runs );
		// The warning was past the limit, but the run still counts as failed.
		self::assertSame( EventRun::STATUS_ERRORED, $runs[0]->status );
		$last = end( $runs[0]->logs );
		self::assertIsArray( $last );
		self::assertStringContainsString( 'more messages were not kept', $last['message'] );
		self::assertStringNotContainsString( 'xxxxxxxxxx', (string) wp_json_encode( $runs[0]->logs ) );
	}

	public function testFailedMetricWritesAreRetried(): void {
		global $wpdb;

		delete_site_option( 'crontrol_metrics_last_hour' );
		update_site_option( 'crontrol_metrics_last_hour', '2031-01-02 08:00:00' );

		$fail = static function ( string $query ): string {
			return ( 0 === strpos( ltrim( $query ), 'INSERT INTO' ) && false !== strpos( $query, 'crontrol_event_metrics' ) ) ? 'SELECT broken FROM' : $query;
		};

		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2031-01-02 12:30:00', new \DateTimeZone( 'UTC' ) ) );
		remove_filter( 'query', $fail );
		$wpdb->suppress_errors( $suppress );

		self::assertSame( '2031-01-02 08:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );

		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );
	}

	public function testLargeValuesAreSummarisedNotFormatted(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_large_value' );

		add_action( 'crontrol_test_large_value', static function (): void {
			do_action( 'crontrol/info', array_fill( 0, 20000, str_repeat( 'x', 100 ) ) );
			do_action( 'crontrol/assert', false, 'Large assertion', array_fill( 0, 20000, 1 ) );
		} );

		do_action( 'crontrol_test_large_value' );

		$runs = $this->runs( 'crontrol_test_large_value' );

		self::assertCount( 1, $runs );
		self::assertStringStartsWith( 'array(20000)', $runs[0]->logs[0]['message'] );
		self::assertLessThan( 1100, strlen( $runs[0]->logs[0]['message'] ) );
		self::assertStringContainsString( 'Assertion failed: Large assertion', $runs[0]->errors[0]['message'] );
	}

	public function testLoggingLargeValuesFitsInALowMemoryLimit(): void {
		$logger = dirname( __DIR__, 2 ) . '/src/Pro/logger.php';
		$code = <<<'PHP'
function __( $text ) { return $text; }
function add_action() {}
class WP_Error {}
require $argv[1];
$logger = new Crontrol\Pro\Logger();
$logger->info( 'Result: {0}', array_fill( 0, 50000, str_repeat( 'x', 2000 ) ) );
$logger->error( new RuntimeException( str_repeat( 'y', 40 * 1024 * 1024 ) ) );
$logger->assert( false, str_repeat( 'z', 30 * 1024 * 1024 ) );
$text = str_repeat( 'w', 40 * 1024 * 1024 );
$text[0] = "\n";
$logger->info( $text );
unset( $text );
$logger->info( array( str_repeat( 'k', 40 * 1024 * 1024 ) => 1 ) );
echo count( $logger->get_logs() );
PHP;

		$output = array();
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=64M -r ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $logger ) . ' 2>&1', $output, $status );

		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( '5', end( $output ) );
	}

	public function testStoredArgumentsAreSummarised(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		add_filter( 'crontrol/log_event_args', '__return_true' );
		$this->observe( 'crontrol_test_args' );

		do_action( 'crontrol_test_args', array_fill( 0, 100000, str_repeat( 'a', 1000 ) ), 'short' );

		remove_all_filters( 'crontrol/log_event_args' );

		$runs = $this->runs( 'crontrol_test_args' );

		self::assertCount( 1, $runs );
		self::assertCount( 2, $runs[0]->args );
		self::assertIsString( $runs[0]->args[0] );
		self::assertStringStartsWith( 'array(100000)', $runs[0]->args[0] );
		self::assertLessThan( 1100, strlen( $runs[0]->args[0] ) );
		self::assertSame( 'short', $runs[0]->args[1] );
	}

	public function testExceptionHandlerStackIsLeftAsItWas(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		$this->observe( 'crontrol_test_handler' );

		$mine = static function ( \Throwable $e ): void {};
		$before = set_exception_handler( $mine );

		do_action( 'crontrol_test_handler' );
		do_action( 'crontrol_test_handler' );

		$top = set_exception_handler( null );
		restore_exception_handler();

		self::assertSame( $mine, $top );

		restore_exception_handler();

		$after = set_exception_handler( null );
		restore_exception_handler();

		self::assertSame( $before, $after );
		self::assertCount( 2, $this->runs( 'crontrol_test_handler' ) );
	}

	public function testFirstFailedMetricHourIsKeptForRetry(): void {
		global $wpdb;

		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );

		$fail = static function ( string $query ): string {
			return ( 0 === strpos( ltrim( $query ), 'INSERT INTO' ) && false !== strpos( $query, 'crontrol_event_metrics' ) ) ? 'SELECT broken FROM' : $query;
		};

		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2032-01-02 12:30:00', new \DateTimeZone( 'UTC' ) ) );
		remove_filter( 'query', $fail );
		$wpdb->suppress_errors( $suppress );

		// The hour before 11:00 is recorded, so 11:00 is tried again on the next run.
		self::assertSame( '2032-01-02 10:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );

		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2032-01-02 13:30:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( '2032-01-02 12:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );

		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );
	}

	public function testLogDetailsAreBounded(): void {
		$entries = array();

		for ( $i = 0; $i < 600; $i++ ) {
			$entries[] = array(
				'level'   => 'debug',
				'message' => str_repeat( 'x', 2000 ),
			);
		}

		$bounded = Log::bound_entries( $entries );
		$last = end( $bounded );

		self::assertLessThan( 50000, strlen( (string) wp_json_encode( $bounded ) ) );
		self::assertIsArray( $last );
		self::assertStringContainsString( 'more entries were not saved', $last['message'] );
		self::assertStringEndsWith( '[truncated]', $bounded[0]['message'] );
	}

	public function testClearingLogsDoesNotReuseIDs(): void {
		$first = EventRun::start( 'crontrol_test_ids', '', time(), array() );
		EventRunModel::create( $first );

		EventRunModel::clear_records();

		$second = EventRun::start( 'crontrol_test_ids', '', time(), array() );
		EventRunModel::create( $second );

		self::assertGreaterThan( $first->ID, $second->ID );
	}

	public function testMissedHoursAreAggregatedLater(): void {
		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );

		$now = new \DateTimeImmutable( '2030-01-02 12:30:00', new \DateTimeZone( 'UTC' ) );
		update_site_option( 'crontrol_metrics_last_hour', '2030-01-02 08:00:00' );

		\Crontrol\Pro\aggregate_missing_metrics( $now );

		self::assertSame( '2030-01-02 11:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );
		self::assertSame( '2030-01-01 00:00:00', get_site_option( 'crontrol_metrics_last_day' ) );

		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );
	}

	public function testArgumentsDisplayIsEscaped(): void {
		$html = \Crontrol\Event\get_hook_args_display(
			\Crontrol\Event\URLCronEvent::HOOK_NAME,
			array(
				array(
					'url'    => 'https://example.org/<img src=x onerror=alert(1)>',
					'method' => 'GET',
				),
			)
		);

		self::assertIsString( $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( '&lt;img', $html );
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

	/**
	 * @throws \RuntimeException Always.
	 */
	public static function throw_inner_failure(): void {
		throw new \RuntimeException( 'Inner failure' );
	}
}
