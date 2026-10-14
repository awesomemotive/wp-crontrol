<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Event\Table;
use Crontrol\Pro\EventMetric;
use Crontrol\Pro\EventMetricModel;
use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\Log;

/**
 * Regression tests for the logging fixes from the 1.23 review.
 */
final class LoggingFixesTest extends Test {
	#[\Override]
	public function set_up() {
		parent::set_up();

		EventRunModel::truncate();
		EventMetricModel::truncate();
		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );
	}

	#[\Override]
	public function tear_down() {
		Log::get_instance()->unobserve( 'crontrol_test_orphan' );
		EventRunModel::truncate();
		EventMetricModel::truncate();
		delete_site_option( 'crontrol_metrics_last_hour' );
		delete_site_option( 'crontrol_metrics_last_day' );

		parent::tear_down();
	}

	/**
	 * @phpstan-param EventRun::STATUS_* $status
	 */
	private function add_run( string $started_at, string $status, bool $fatal = false, int $duration = 100 ): EventRun {
		$run               = EventRun::start( 'crontrol_test_metric', '', time(), array() );
		$run->started_at   = new \DateTimeImmutable( $started_at, new \DateTimeZone( 'UTC' ) );
		$run->scheduled_at = $run->started_at;
		$run->status       = $status;
		$run->fatal        = $fatal;
		$run->duration     = $duration;
		EventRunModel::create( $run );

		return $run;
	}

	private function metric( string $type, string $bucket ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(value) FROM %i WHERE metric_type = %s AND time_bucket = %s',
				EventMetricModel::get_table(),
				$type,
				$bucket
			)
		);
	}

	/**
	 * Logging a hook must not hide that the hook has no action.
	 */
	public function testObserversAreNotCountedAsActions(): void {
		Log::get_instance()->observe( 'crontrol_test_orphan' );

		self::assertTrue( has_action( 'crontrol_test_orphan' ) );
		self::assertSame( array(), \Crontrol\get_hook_callbacks( 'crontrol_test_orphan' ) );

		$event    = Event::create( 'crontrol_test_orphan', time() + 3600, md5( serialize( array() ) ), array(), null, null );
		$filtered = Table::get_filtered_events( array( 'orphan' => $event ) );

		self::assertCount( 1, $filtered['noaction'] );

		// A real callback is still listed alongside the observers.
		$callback = static function (): void {};
		add_action( 'crontrol_test_orphan', $callback );
		self::assertCount( 1, \Crontrol\get_hook_callbacks( 'crontrol_test_orphan' ) );
		remove_action( 'crontrol_test_orphan', $callback );
	}

	public function testObserverIdsAreForgottenWhenUnobserved(): void {
		global $wp_filter;

		Log::get_instance()->observe( 'crontrol_test_orphan' );
		$callbacks = $wp_filter['crontrol_test_orphan']->callbacks;
		$start     = reset( $callbacks[-9999] )['function'];

		self::assertTrue( Log::is_observer( $start ) );

		Log::get_instance()->unobserve( 'crontrol_test_orphan' );

		self::assertFalse( Log::is_observer( $start ) );
	}

	/**
	 * Non-fatal failures (HTTP, Action Scheduler, logged errors) count as errors in Insights.
	 */
	public function testNonFatalErroredRunsAreCountedAsErrors(): void {
		$this->add_run( '2033-03-01 10:15:00', EventRun::STATUS_ERRORED, false );
		$this->add_run( '2033-03-01 10:20:00', EventRun::STATUS_ERRORED, true );
		$this->add_run( '2033-03-01 10:25:00', EventRun::STATUS_COMPLETED );

		EventMetricModel::aggregate_errors_hourly( new \DateTimeImmutable( '2033-03-01 10:00:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( 2, $this->metric( EventMetric::TYPE_ERRORS_HOURLY, '2033-03-01 10:00:00' ) );
	}

	/**
	 * A run that starts at 10:59 and finishes after 10:00 was aggregated is still counted.
	 */
	public function testLateFinishingRunsAreCounted(): void {
		update_site_option( 'crontrol_metrics_last_hour', '2033-03-01 09:00:00' );
		update_site_option( 'crontrol_metrics_last_day', '2033-02-27 00:00:00' );

		$run = $this->add_run( '2033-03-01 10:59:00', EventRun::STATUS_RUNNING );

		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2033-03-01 11:01:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( '2033-03-01 10:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );
		self::assertSame( 0, $this->metric( EventMetric::TYPE_ERRORS_HOURLY, '2033-03-01 10:00:00' ) );

		$run->status   = EventRun::STATUS_ERRORED;
		$run->duration = 180000;
		EventRunModel::update( $run );

		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2033-03-01 12:05:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( 1, $this->metric( EventMetric::TYPE_ERRORS_HOURLY, '2033-03-01 10:00:00' ) );
		self::assertSame( '2033-03-01 11:00:00', get_site_option( 'crontrol_metrics_last_hour' ) );

		// Recomputing again doesn't double count.
		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2033-03-01 12:35:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( 1, $this->metric( EventMetric::TYPE_ERRORS_HOURLY, '2033-03-01 10:00:00' ) );
	}

	/**
	 * The recompute window is bounded: hours older than it are left alone.
	 */
	public function testOldHoursAreNotRecomputed(): void {
		update_site_option( 'crontrol_metrics_last_hour', '2033-03-01 05:00:00' );
		update_site_option( 'crontrol_metrics_last_day', '2033-02-27 00:00:00' );

		$this->add_run( '2033-03-01 05:30:00', EventRun::STATUS_ERRORED );

		\Crontrol\Pro\aggregate_missing_metrics( new \DateTimeImmutable( '2033-03-01 12:05:00', new \DateTimeZone( 'UTC' ) ) );

		self::assertSame( 0, $this->metric( EventMetric::TYPE_ERRORS_HOURLY, '2033-03-01 05:00:00' ) );
	}
}
