<?php

namespace Crontrol\Pro;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the historical metrics data sets used by the charts on the Cron Insights screen.
 *
 * Where {@see Insights} projects the site's *upcoming* scheduled events, this class summarises the
 * aggregated historical metrics recorded in the event metrics table (see {@see EventMetricModel}).
 */
final class MetricsInsights {
	/**
	 * Number of days of hourly error/warning history to chart.
	 */
	public const HOURLY_DAYS = 7;

	/**
	 * Number of days of daily hook duration history to chart.
	 */
	public const DAILY_DAYS = 30;

	/**
	 * Maximum number of hooks to plot on the duration trend chart, by total duration.
	 */
	public const TOP_HOOKS = 8;

	/**
	 * Threshold passed to the slow hook detection (1.1 = 10% slower than baseline).
	 */
	public const SLOW_THRESHOLD = 1.1;

	/**
	 * Row cap for the metric queries.
	 *
	 * The QueryBuilder paginates to a small default page size, so an explicit limit is passed to
	 * retrieve the full time range. The aggregated tables hold at most a handful of rows per hook
	 * per bucket, so this comfortably covers the charted windows.
	 */
	private const QUERY_LIMIT = 10000;

	/**
	 * Builds the full historical metrics data set for the front end.
	 *
	 * @return array{
	 *   errors_warnings: array{labels: list<int>, errors: list<int>},
	 *   duration_trend: array{labels: list<int>, hooks: list<array{hook: string, data: list<int|null>}>},
	 *   slow_hooks: list<array{hook: string, baseline_avg: int, recent_avg: int, increase_pct: float}>,
	 *   totals: array{errors: int, warnings: int},
	 * }
	 */
	public static function get(): array {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return array(
			'errors_warnings' => self::errors_warnings( $now ),
			'duration_trend'  => self::duration_trend( $now ),
			'slow_hooks'      => self::slow_hooks( $now ),
			'totals'          => self::totals( $now ),
		);
	}

	/**
	 * Builds a dense hourly error series over the recent window.
	 *
	 * @param DateTimeImmutable $now The current time, in UTC.
	 * @return array{labels: list<int>, errors: list<int>}
	 */
	private static function errors_warnings( DateTimeImmutable $now ): array {
		$end = $now->setTime( (int) $now->format( 'H' ), 0, 0 );
		$start = $end->modify( sprintf( '-%d hours', ( self::HOURLY_DAYS * 24 ) - 1 ) );

		$errors = self::index_by_bucket( EventMetricModel::get_metrics( EventMetric::TYPE_ERRORS_HOURLY, $start, $end, null, false, self::QUERY_LIMIT ), 'Y-m-d H:i:s' );

		$labels = array();
		$error_series = array();

		for ( $cursor = $start; $cursor <= $end; $cursor = $cursor->modify( '+1 hour' ) ) {
			$key = $cursor->format( 'Y-m-d H:i:s' );
			$labels[] = $cursor->getTimestamp();
			$error_series[] = $errors[ $key ] ?? 0;
		}

		return array(
			'labels' => $labels,
			'errors' => $error_series,
		);
	}

	/**
	 * Builds per-hook daily average duration series for the busiest hooks.
	 *
	 * Missing days are represented as null so the line chart shows a gap rather than dropping to
	 * zero on days with no recorded runs.
	 *
	 * @param DateTimeImmutable $now The current time, in UTC.
	 * @return array{labels: list<int>, hooks: list<array{hook: string, data: list<int|null>}>}
	 */
	private static function duration_trend( DateTimeImmutable $now ): array {
		$end = $now->setTime( 0, 0, 0 );
		$start = $end->modify( sprintf( '-%d days', self::DAILY_DAYS - 1 ) );

		$metrics = EventMetricModel::get_metrics( EventMetric::TYPE_HOOK_DURATION_DAILY, $start, $end, null, false, self::QUERY_LIMIT );

		$by_hook = array();
		$totals = array();

		foreach ( $metrics as $metric ) {
			$day = $metric->time_bucket->format( 'Y-m-d' );
			$by_hook[ $metric->dimension ][ $day ] = $metric->value;
			$totals[ $metric->dimension ] = ( $totals[ $metric->dimension ] ?? 0 ) + $metric->value;
		}

		// Plot only the hooks with the greatest total duration so the chart stays readable.
		arsort( $totals );
		$top_hooks = array_slice( array_keys( $totals ), 0, self::TOP_HOOKS );

		$labels = array();
		$days = array();

		for ( $cursor = $start; $cursor <= $end; $cursor = $cursor->modify( '+1 day' ) ) {
			$labels[] = $cursor->getTimestamp();
			$days[] = $cursor->format( 'Y-m-d' );
		}

		$hooks = array();

		foreach ( $top_hooks as $hook ) {
			$series = array();

			foreach ( $days as $day ) {
				$series[] = isset( $by_hook[ $hook ][ $day ] ) ? (int) $by_hook[ $hook ][ $day ] : null;
			}

			$hooks[] = array(
				'hook' => $hook,
				'data' => $series,
			);
		}

		return array(
			'labels' => $labels,
			'hooks'  => $hooks,
		);
	}

	/**
	 * Detects hooks that have slowed down, comparing the older half of the available daily history
	 * against the more recent half so the comparison adapts to however much history exists.
	 *
	 * @param DateTimeImmutable $now The current time, in UTC.
	 * @return list<array{hook: string, baseline_avg: int, recent_avg: int, increase_pct: float}>
	 */
	private static function slow_hooks( DateTimeImmutable $now ): array {
		$utc = new DateTimeZone( 'UTC' );
		$window_start = $now->modify( '-90 days' )->setTime( 0, 0, 0 );
		$window_end = $now->setTime( 0, 0, 0 );

		$metrics = EventMetricModel::get_metrics( EventMetric::TYPE_HOOK_DURATION_DAILY, $window_start, $window_end, null, false, self::QUERY_LIMIT );

		if ( count( $metrics ) < 2 ) {
			return array();
		}

		$min = null;
		$max = null;

		foreach ( $metrics as $metric ) {
			$timestamp = $metric->time_bucket->getTimestamp();
			$min = ( null === $min ) ? $timestamp : min( $min, $timestamp );
			$max = ( null === $max ) ? $timestamp : max( $max, $timestamp );
		}

		$earliest = ( new DateTimeImmutable( '@' . $min ) )->setTimezone( $utc );
		$latest = ( new DateTimeImmutable( '@' . $max ) )->setTimezone( $utc );
		$midpoint = ( new DateTimeImmutable( '@' . intdiv( $min + $max, 2 ) ) )->setTimezone( $utc );

		$anomalies = EventMetricModel::detect_slow_hooks(
			$earliest,
			$midpoint,
			$midpoint,
			$latest,
			self::SLOW_THRESHOLD
		);

		return array_values( $anomalies );
	}

	/**
	 * Totals the errors and warnings recorded over the recent window for the summary tiles.
	 *
	 * @param DateTimeImmutable $now The current time, in UTC.
	 * @return array{errors: int, warnings: int}
	 */
	private static function totals( DateTimeImmutable $now ): array {
		$start = $now->modify( sprintf( '-%d days', self::HOURLY_DAYS ) );

		return array(
			'errors'   => EventMetricModel::get_metric_sum( EventMetric::TYPE_ERRORS_HOURLY, $start, $now ),
			'warnings' => EventMetricModel::get_metric_sum( EventMetric::TYPE_WARNINGS_HOURLY, $start, $now ),
		);
	}

	/**
	 * Indexes a list of metrics by their formatted time bucket, summing values that share a bucket.
	 *
	 * @param list<EventMetric> $metrics The metrics to index.
	 * @param string            $format  The date format to key the bucket by.
	 * @return array<string,int>
	 */
	private static function index_by_bucket( array $metrics, string $format ): array {
		$map = array();

		foreach ( $metrics as $metric ) {
			$key = $metric->time_bucket->format( $format );
			$map[ $key ] = ( $map[ $key ] ?? 0 ) + $metric->value;
		}

		return $map;
	}
}
