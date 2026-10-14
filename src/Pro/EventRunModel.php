<?php

namespace Crontrol\Pro;

/**
 * @extends Model<EventRun>
 */
final class EventRunModel extends Model {
	protected const VERSION = 12;
	protected const TABLE = 'crontrol_event_logs';

	/**
	 * Returns the class name of the item managed by this model.
	 *
	 * @phpstan-return class-string<EventRun>
	 */
	#[\Override]
	public static function get_item_class(): string {
		return EventRun::class;
	}

	/**
	 * Returns a list of index names and their fields.
	 *
	 * @return array<string,string>
	 */
	#[\Override]
	protected static function get_field_indexes(): array {
		return [
			// Covers fetching all runs for a given hook:
			'id_hook' => 'site_id, hook',
			// Covers sorting by duration and fetching runs over a given duration:
			'id_duration' => 'site_id, duration',
			// Covers time-range + status used by the metric aggregation queries:
			'started_status' => 'started_at, status',
		];
	}

	/**
	 * Returns the most recent run time logged for each hook on the current site,
	 * keyed by hook name.
	 *
	 * Fetches every hook's latest run in a single grouped query.
	 *
	 * @return array<string,\DateTimeImmutable>
	 */
	public static function get_last_runs(): array {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'
					SELECT hook, MAX(started_at) AS last_run
					FROM %i
					WHERE site_id = %d
					GROUP BY hook
				',
				$table,
				get_current_blog_id()
			)
		);

		if ( ! is_array( $results ) ) {
			return [];
		}

		$last_runs = [];

		foreach ( $results as $result ) {
			$last_runs[ (string) $result->hook ] = new \DateTimeImmutable( (string) $result->last_run, new \DateTimeZone( 'UTC' ) );
		}

		return $last_runs;
	}

	/**
	 * Clears all logged runs for a single hook on the current site.
	 *
	 * @param string $hook Hook name.
	 * @return bool True on success, false on failure.
	 */
	public static function clear_records_for_hook( string $hook ): bool {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$where = [ 'hook' => $hook ];
		$formats = [ '%s' ];

		// Restrict to the current site in multisite, matching clear_records().
		if ( is_multisite() ) {
			$where['site_id'] = get_current_blog_id();
			$formats[] = '%d';
		}

		$result = $wpdb->delete( $table, $where, $formats );

		return $result !== false;
	}

	/**
	 * Returns whether any run that started in the given period, on any site, is still running.
	 *
	 * Metrics wait for these runs to finish, so their result and duration are counted.
	 *
	 * @param \DateTimeImmutable $from Start of the period, in UTC.
	 * @param \DateTimeImmutable $to   End of the period, in UTC, exclusive.
	 */
	public static function has_running( \DateTimeImmutable $from, \DateTimeImmutable $to ): bool {
		$wpdb = self::wpdb();

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE started_at >= %s AND started_at < %s AND status = %s LIMIT 1',
				self::get_table(),
				$from->format( 'Y-m-d H:i:s.v' ),
				$to->format( 'Y-m-d H:i:s.v' ),
				EventRun::STATUS_RUNNING
			)
		);
	}

	/**
	 * Returns whether any logged run on the current site has an errored status.
	 *
	 * @return bool True if at least one run errored.
	 */
	public static function has_errors(): bool {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'
					SELECT 1
					FROM %i
					WHERE site_id = %d
					AND status = %s
					LIMIT 1
				',
				$table,
				get_current_blog_id(),
				EventRun::STATUS_ERRORED,
			)
		);

		return $result !== null;
	}

	/**
	 * @return array<int,string>
	 */
	public static function get_unique_hooks(): array {
		$wpdb = self::wpdb();
		$table = self::get_table();

		return array_values( array_filter( $wpdb->get_col(
			$wpdb->prepare(
				'
					SELECT DISTINCT hook
					FROM %i
					WHERE site_id = %d
					ORDER BY hook ASC
				',
				$table,
				get_current_blog_id()
			)
		), 'is_string' ) );
	}

	/**
	 * Trims the logs of every site: entries older than the given number of days are deleted, then
	 * each site keeps at most its most recent {@see Model::SOFT_LIMIT} entries.
	 *
	 * @param int $days Number of days of logs to keep.
	 * @return int Number of rows deleted.
	 */
	public static function prune_sites( int $days ): int {
		$wpdb = self::wpdb();
		$table = self::get_table();
		$deleted = 0;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				'DELETE FROM %i WHERE started_at < %s',
				$table,
				$cutoff
			)
		);
		$deleted += is_int( $result ) ? $result : 0;

		$site_ids = array_map( 'intval', $wpdb->get_col(
			(string) $wpdb->prepare( 'SELECT DISTINCT site_id FROM %i', $table )
		) );

		foreach ( $site_ids as $site_id ) {
			$cutoff_id = $wpdb->get_var(
				(string) $wpdb->prepare(
					'SELECT ID FROM %i WHERE site_id = %d ORDER BY ID DESC LIMIT %d, 1',
					$table,
					$site_id,
					self::SOFT_LIMIT - 1
				)
			);

			if ( null === $cutoff_id ) {
				continue;
			}

			$result = $wpdb->query(
				(string) $wpdb->prepare(
					'DELETE FROM %i WHERE site_id = %d AND ID < %d',
					$table,
					$site_id,
					(int) $cutoff_id
				)
			);
			$deleted += is_int( $result ) ? $result : 0;
		}

		return $deleted;
	}
}
