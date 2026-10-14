<?php

namespace Crontrol\Pro;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Model for managing aggregated event metrics.
 *
 * @extends Model<EventMetric>
 */
final class EventMetricModel extends Model {
	protected const VERSION = 5;
	protected const TABLE = 'crontrol_event_metrics';

	/**
	 * Returns the class name of the item managed by this model.
	 *
	 * @phpstan-return class-string<EventMetric>
	 */
	#[\Override]
	public static function get_item_class(): string {
		return EventMetric::class;
	}

	/**
	 * Returns a list of index names and their fields.
	 *
	 * Indexes:
	 * - idx_type_time_site: Composite index covering all query patterns
	 *   - metric_type is always present (required for all queries)
	 *   - time_bucket is always present (required for all queries)
	 *   - site_id is optional (omitted for network-wide queries)
	 *   This order allows the index to be used for both per-site and network-wide queries
	 * - idx_dimension: For filtering by dimension
	 *
	 * @return array<string,string>
	 */
	#[\Override]
	protected static function get_field_indexes(): array {
		return [
			'idx_type_time_site' => 'metric_type, time_bucket, site_id',
			'idx_dimension' => 'dimension',
		];
	}

	/**
	 * Aggregates hourly error counts for a specific hour.
	 *
	 * @param DateTimeImmutable $hour The hour to aggregate (e.g., '2025-01-15 14:00:00').
	 * @return int|null Number of metric records created, or null if the query failed.
	 */
	public static function aggregate_errors_hourly( DateTimeImmutable $hour ): ?int {
		$wpdb = self::wpdb();
		$event_table = EventRunModel::get_table();
		$metric_table = self::get_table();

		// Normalize to start of hour
		$hour_start = $hour->setTime( (int) $hour->format( 'H' ), 0, 0 );
		$hour_end = $hour_start->modify( '+1 hour' );

		// Bail out if metrics for this hour have already been aggregated.
		$existing = $wpdb->get_var(
			(string) $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE metric_type = %s AND time_bucket = %s',
				$metric_table,
				EventMetric::TYPE_ERRORS_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' )
			)
		);

		if ( (int) $existing > 0 ) {
			return 0;
		}

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				"
					INSERT INTO %i (site_id, metric_type, time_bucket, dimension, value)
					SELECT
						site_id,
						%s,
						%s AS hour_bucket,
						'error',
						COUNT(*)
					FROM %i
					WHERE status = %s
					AND started_at >= %s
					AND started_at < %s
					GROUP BY site_id
				",
				$metric_table,
				EventMetric::TYPE_ERRORS_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' ),
				$event_table,
				EventRun::STATUS_ERRORED,
				$hour_start->format( 'Y-m-d H:i:s.v' ),
				$hour_end->format( 'Y-m-d H:i:s.v' )
			)
		);

		return $result === false ? null : (int) $result;
	}

	/**
	 * Aggregates hourly warning counts for a specific hour.
	 *
	 * @param DateTimeImmutable $hour The hour to aggregate (e.g., '2025-01-15 14:00:00').
	 * @return int|null Number of metric records created, or null if the query failed.
	 */
	public static function aggregate_warnings_hourly( DateTimeImmutable $hour ): ?int {
		$wpdb = self::wpdb();
		$event_table = EventRunModel::get_table();
		$metric_table = self::get_table();

		// Normalize to start of hour
		$hour_start = $hour->setTime( (int) $hour->format( 'H' ), 0, 0 );
		$hour_end = $hour_start->modify( '+1 hour' );

		// Bail out if metrics for this hour have already been aggregated.
		$existing = $wpdb->get_var(
			(string) $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE metric_type = %s AND time_bucket = %s',
				$metric_table,
				EventMetric::TYPE_WARNINGS_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' )
			)
		);

		if ( (int) $existing > 0 ) {
			return 0;
		}

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				"
					INSERT INTO %i (site_id, metric_type, time_bucket, dimension, value)
					SELECT
						site_id,
						%s,
						%s AS hour_bucket,
						'warning',
						COUNT(*)
					FROM %i
					WHERE warning = 1
					AND started_at >= %s
					AND started_at < %s
					GROUP BY site_id
				",
				$metric_table,
				EventMetric::TYPE_WARNINGS_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' ),
				$event_table,
				$hour_start->format( 'Y-m-d H:i:s.v' ),
				$hour_end->format( 'Y-m-d H:i:s.v' )
			)
		);

		return $result === false ? null : (int) $result;
	}

	/**
	 * Aggregates daily average hook durations for a specific day.
	 *
	 * @param DateTimeImmutable $day The day to aggregate (e.g., 'yesterday').
	 * @return int|null Number of metric records created, or null if the query failed.
	 */
	public static function aggregate_hook_duration_daily( DateTimeImmutable $day ): ?int {
		$wpdb = self::wpdb();
		$event_table = EventRunModel::get_table();
		$metric_table = self::get_table();

		// Normalize to start of day
		$day_start = $day->setTime( 0, 0, 0 );
		$day_end = $day_start->modify( '+1 day' );

		// Bail out if metrics for this day have already been aggregated.
		$existing = $wpdb->get_var(
			(string) $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE metric_type = %s AND time_bucket = %s',
				$metric_table,
				EventMetric::TYPE_HOOK_DURATION_DAILY,
				$day_start->format( 'Y-m-d H:i:s' )
			)
		);

		if ( (int) $existing > 0 ) {
			return 0;
		}

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				"
					INSERT INTO %i (site_id, metric_type, time_bucket, dimension, value)
					SELECT
						site_id,
						%s,
						%s AS day_bucket,
						hook,
						AVG(duration) AS avg_duration
					FROM %i
					WHERE started_at >= %s
					AND started_at < %s
					AND status = 'completed'
					GROUP BY site_id, hook
				",
				$metric_table,
				EventMetric::TYPE_HOOK_DURATION_DAILY,
				$day_start->format( 'Y-m-d H:i:s' ),
				$event_table,
				$day_start->format( 'Y-m-d H:i:s.v' ),
				$day_end->format( 'Y-m-d H:i:s.v' )
			)
		);

		return $result === false ? null : (int) $result;
	}

	/**
	 * Aggregates hourly average hook durations for a specific hour.
	 *
	 * @param DateTimeImmutable $hour The hour to aggregate (e.g., '2025-01-15 14:00:00').
	 * @return int|null Number of metric records created, or null if the query failed.
	 */
	public static function aggregate_hook_duration_hourly( DateTimeImmutable $hour ): ?int {
		$wpdb = self::wpdb();
		$event_table = EventRunModel::get_table();
		$metric_table = self::get_table();

		// Normalize to start of hour
		$hour_start = $hour->setTime( (int) $hour->format( 'H' ), 0, 0 );
		$hour_end = $hour_start->modify( '+1 hour' );

		// Bail out if metrics for this hour have already been aggregated.
		$existing = $wpdb->get_var(
			(string) $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE metric_type = %s AND time_bucket = %s',
				$metric_table,
				EventMetric::TYPE_HOOK_DURATION_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' )
			)
		);

		if ( (int) $existing > 0 ) {
			return 0;
		}

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				"
					INSERT INTO %i (site_id, metric_type, time_bucket, dimension, value)
					SELECT
						site_id,
						%s,
						%s AS hour_bucket,
						hook,
						AVG(duration) AS avg_duration
					FROM %i
					WHERE started_at >= %s
					AND started_at < %s
					AND status = 'completed'
					GROUP BY site_id, hook
				",
				$metric_table,
				EventMetric::TYPE_HOOK_DURATION_HOURLY,
				$hour_start->format( 'Y-m-d H:i:s' ),
				$event_table,
				$hour_start->format( 'Y-m-d H:i:s.v' ),
				$hour_end->format( 'Y-m-d H:i:s.v' )
			)
		);

		return $result === false ? null : (int) $result;
	}

	/**
	 * Aggregates all hourly metrics for a specific hour and daily metrics for a specific day.
	 *
	 * @param DateTimeImmutable $hour The completed hour for hourly metrics.
	 * @param DateTimeImmutable $day The completed day for daily metrics.
	 * @return array<string,int|null> Number of metric records created per type, null where the query failed.
	 */
	public static function aggregate_all_metrics( DateTimeImmutable $hour, DateTimeImmutable $day ): array {
		return [
			'errors_hourly' => self::aggregate_errors_hourly( $hour ),
			'warnings_hourly' => self::aggregate_warnings_hourly( $hour ),
			'hook_duration_daily' => self::aggregate_hook_duration_daily( $day ),
			'hook_duration_hourly' => self::aggregate_hook_duration_hourly( $hour ),
		];
	}

	/**
	 * Gets metrics for a specific type and time range.
	 *
	 * Note: Results are ordered by ID DESC (most recent first) as per QueryBuilder default.
	 *
	 * @param string $metric_type Type of metric to retrieve.
	 * @param DateTimeImmutable $start Start time.
	 * @param DateTimeImmutable $end End time.
	 * @param string|null $dimension Optional dimension filter.
	 * @param bool $network_wide Whether to query across all sites (multisite only).
	 * @param int|null $limit Maximum number of rows to return. Defaults to the QueryBuilder page
	 *                        size; pass a higher value to retrieve a full time range for charting.
	 * @return list<EventMetric>
	 */
	public static function get_metrics(
		string $metric_type,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?string $dimension = null,
		bool $network_wide = false,
		?int $limit = null
	): array {
		$query = self::query();

		if ( $network_wide ) {
			$query->query_network();
		}

		if ( null !== $limit ) {
			$query->per_page( $limit );
		}

		$query->where( 'metric_type', QueryBuilder::OPERATOR_EQUALS, $metric_type )
			->where( 'time_bucket', QueryBuilder::OPERATOR_GREATER_THAN_OR_EQUAL, $start )
			->where( 'time_bucket', QueryBuilder::OPERATOR_LESS_THAN_OR_EQUAL, $end );

		if ( $dimension !== null ) {
			$query->where( 'dimension', QueryBuilder::OPERATOR_EQUALS, $dimension );
		}

		return $query->get();
	}

	/**
	 * Gets the sum of metric values for a specific type and time range.
	 *
	 * @param string $metric_type Type of metric to sum.
	 * @param DateTimeImmutable $start Start time.
	 * @param DateTimeImmutable $end End time.
	 * @param string|null $dimension Optional dimension filter.
	 * @param bool $network_wide Whether to query across all sites (multisite only).
	 * @return int Total value.
	 */
	public static function get_metric_sum(
		string $metric_type,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?string $dimension = null,
		bool $network_wide = false
	): int {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$where_clauses = [];
		$where_values = [ $table ];

		$where_clauses[] = 'metric_type = %s';
		$where_values[] = $metric_type;

		$where_clauses[] = 'time_bucket >= %s';
		$where_values[] = $start->format( 'Y-m-d H:i:s' );

		$where_clauses[] = 'time_bucket <= %s';
		$where_values[] = $end->format( 'Y-m-d H:i:s' );

		if ( $dimension !== null ) {
			$where_clauses[] = 'dimension = %s';
			$where_values[] = $dimension;
		}

		// Add site_id filter unless querying network-wide
		if ( ! $network_wide && is_multisite() ) {
			$where_clauses[] = 'site_id = %d';
			$where_values[] = get_current_blog_id();
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// $where_sql is built only from hardcoded clause strings containing placeholders;
		// every dynamic value is passed through $where_values to prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT COALESCE(SUM(value), 0)
					FROM %i
					WHERE {$where_sql}
				",
				$where_values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $result;
	}

	/**
	 * Gets hook performance trends over time.
	 *
	 * Returns average duration for a specific hook across time periods,
	 * useful for detecting slowdowns or anomalies.
	 *
	 * @param string $hook Hook name.
	 * @param DateTimeImmutable $start Start time.
	 * @param DateTimeImmutable $end End time.
	 * @param string $granularity 'daily' or 'hourly'.
	 * @param bool $network_wide Whether to query across all sites (multisite only).
	 * @param int|null $limit Maximum number of rows to return. Defaults to the QueryBuilder page size.
	 * @return list<EventMetric>
	 */
	public static function get_hook_performance_trend(
		string $hook,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		string $granularity = 'daily',
		bool $network_wide = false,
		?int $limit = null
	): array {
		$metric_type = $granularity === 'daily'
			? EventMetric::TYPE_HOOK_DURATION_DAILY
			: EventMetric::TYPE_HOOK_DURATION_HOURLY;

		return self::get_metrics(
			$metric_type,
			$start,
			$end,
			$hook,  // dimension = hook name
			$network_wide,
			$limit
		);
	}

	/**
	 * Detects hook performance anomalies.
	 *
	 * Compares recent performance against historical baseline to identify
	 * hooks that have gotten significantly slower.
	 *
	 * @param DateTimeImmutable $baseline_start Start of baseline period (e.g., -90 days).
	 * @param DateTimeImmutable $baseline_end End of baseline period (e.g., -30 days).
	 * @param DateTimeImmutable $recent_start Start of recent period (e.g., -7 days).
	 * @param DateTimeImmutable $recent_end End of recent period (e.g., now).
	 * @param float $threshold Percentage increase to flag (e.g., 1.5 = 50% slower).
	 * @param bool $network_wide Whether to query across all sites (multisite only).
	 * @return array<string,array{hook: string, baseline_avg: int, recent_avg: int, increase_pct: float}>
	 */
	public static function detect_slow_hooks(
		DateTimeImmutable $baseline_start,
		DateTimeImmutable $baseline_end,
		DateTimeImmutable $recent_start,
		DateTimeImmutable $recent_end,
		float $threshold = 1.5,
		bool $network_wide = false
	): array {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$where = 'metric_type = %s AND time_bucket >= %s AND time_bucket <= %s';
		$site_values = [];

		// Add the site_id filter unless querying network-wide.
		if ( ! $network_wide && is_multisite() ) {
			$where .= ' AND site_id = %d';
			$site_values[] = get_current_blog_id();
		}

		// $where is built only from hardcoded clauses containing placeholders, and
		// $baseline_query/$recent_query are prepare() results passed to get_results().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Get baseline averages per hook
		$baseline_query = $wpdb->prepare(
			"
				SELECT dimension AS hook, AVG(value) AS avg_duration
				FROM %i
				WHERE {$where}
				GROUP BY dimension
			",
			$table,
			EventMetric::TYPE_HOOK_DURATION_DAILY,
			$baseline_start->format( 'Y-m-d H:i:s' ),
			$baseline_end->format( 'Y-m-d H:i:s' ),
			...$site_values
		);

		// Get recent averages per hook
		$recent_query = $wpdb->prepare(
			"
				SELECT dimension AS hook, AVG(value) AS avg_duration
				FROM %i
				WHERE {$where}
				GROUP BY dimension
			",
			$table,
			EventMetric::TYPE_HOOK_DURATION_DAILY,
			$recent_start->format( 'Y-m-d H:i:s' ),
			$recent_end->format( 'Y-m-d H:i:s' ),
			...$site_values
		);

		$baseline_results = $wpdb->get_results( $baseline_query, OBJECT_K ) ?? [];
		$recent_results = $wpdb->get_results( $recent_query, OBJECT_K ) ?? [];
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$anomalies = [];

		foreach ( $recent_results as $hook => $recent ) {
			if ( ! isset( $baseline_results[ $hook ] ) ) {
				continue; // No baseline data
			}

			$baseline_avg = (int) $baseline_results[ $hook ]->avg_duration;
			$recent_avg = (int) $recent->avg_duration;

			if ( $baseline_avg === 0 ) {
				continue; // Avoid division by zero
			}

			$increase_ratio = $recent_avg / $baseline_avg;

			if ( $increase_ratio >= $threshold ) {
				$anomalies[ $hook ] = [
					'hook' => $hook,
					'baseline_avg' => $baseline_avg,
					'recent_avg' => $recent_avg,
					'increase_pct' => ( $increase_ratio - 1.0 ) * 100,
				];
			}
		}

		// Sort by increase percentage descending
		uasort( $anomalies, function ( $a, $b ) {
			return $b['increase_pct'] <=> $a['increase_pct'];
		} );

		return $anomalies;
	}

	/**
	 * Deletes aggregated metrics older than the given number of days.
	 *
	 * @param int $days Number of days of metrics to keep.
	 * @return int Number of rows deleted.
	 */
	public static function prune_older_than( int $days ): int {
		$wpdb = self::wpdb();

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				'DELETE FROM %i WHERE time_bucket < %s',
				self::get_table(),
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
