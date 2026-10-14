<?php

namespace Crontrol\Pro;

/**
 * Computes the event density data and projections used by the Cron Insights screen.
 */
final class Insights {
	/**
	 * Number of hours in the projection window (7 days).
	 */
	public const HOURS = ( 24 * 7 ); // 168

	/**
	 * The bucket slug used for non-recurring events in the weekly view.
	 */
	public const NONRECURRING = 'nonrecurring';

	/**
	 * Builds the full data set from the scheduled cron events.
	 *
	 * @return array{
	 *   event_count: int,
	 *   start_time: int,
	 *   end_time: int,
	 *   busiest_hour: int,
	 *   schedules: array<int,array{
	 *     slug: string,
	 *     label: string,
	 *   }>,
	 *   event_schedules: array<string,string>,
	 *   weekly: array<string,array<int,int>>,
	 *   hours: array<int,array<string,array<int,int>>>,
	 *   non_recurring: array<int,array{
	 *     name: string,
	 *     next_run: int,
	 *   }>,
	 *   outside_range: array<int,array{
	 *     name: string,
	 *     next_run: int,
	 *     schedule: string|false,
	 *   }>,
	 * }
	 */
	public static function get(): array {
		$events = \Crontrol\Event\get();
		$schedules = self::schedules();
		$weekly = array();
		$hours = array();
		$schedule_labels = array();
		$event_schedules = array();
		$non_recurring = array();
		$outside_range = array();

		// The upcoming view starts now, not at the oldest overdue event.
		$start_time = intdiv( time(), HOUR_IN_SECONDS ) * HOUR_IN_SECONDS;
		$end_time = $start_time + ( self::HOURS * HOUR_IN_SECONDS );

		foreach ( $schedules as $schedule ) {
			$weekly[ $schedule['slug'] ] = array_fill( 0, self::HOURS, 0 );
			$schedule_labels[ $schedule['slug'] ] = $schedule['label'];
		}

		foreach ( $events as $event ) {
			$occurrences = self::occurrences( $event, $start_time, $end_time );
			$recurring = self::is_recurring( $event );

			// Classify events that don't fire within the window for their dedicated panels.
			if ( ! $recurring ) {
				if ( $event->timestamp >= $start_time && $event->timestamp < $end_time ) {
					$non_recurring[] = array(
						'name'     => $event->hook,
						'next_run' => $event->timestamp,
					);
				} else {
					$outside_range[] = array(
						'name'     => $event->hook,
						'next_run' => $event->timestamp,
						'schedule' => false,
					);
				}
			} elseif ( empty( $occurrences ) ) {
				$outside_range[] = array(
					'name'     => $event->hook,
					'next_run' => $event->timestamp,
					'schedule' => (string) $event->schedule,
				);
			}

			if ( $recurring && is_string( $event->schedule ) && isset( $weekly[ $event->schedule ] ) ) {
				$type = $event->schedule;
			} else {
				$type = self::NONRECURRING;
			}

			// Map each hook to its schedule's label for the minutely legend, preferring a
			// recurring schedule if the same hook happens to be scheduled more than once.
			if ( ! isset( $event_schedules[ $event->hook ] ) || self::NONRECURRING !== $type ) {
				$event_schedules[ $event->hook ] = $schedule_labels[ $type ];
			}

			foreach ( $occurrences as $timestamp ) {
				$hour_index = intdiv( $timestamp - $start_time, HOUR_IN_SECONDS );

				if ( $hour_index < 0 || $hour_index >= self::HOURS ) {
					continue;
				}

				++$weekly[ $type ][ $hour_index ];

				$minute_index = intdiv( $timestamp - ( $start_time + ( $hour_index * HOUR_IN_SECONDS ) ), MINUTE_IN_SECONDS );

				if ( $minute_index < 0 || $minute_index >= MINUTE_IN_SECONDS ) {
					continue;
				}

				// Stored sparsely as { minute_index: count } since most minutes are empty;
				// the front end expands these into dense 60-slot arrays for charting.
				if ( ! isset( $hours[ $hour_index ][ $event->hook ][ $minute_index ] ) ) {
					$hours[ $hour_index ][ $event->hook ][ $minute_index ] = 0;
				}

				++$hours[ $hour_index ][ $event->hook ][ $minute_index ];
			}
		}

		return array(
			'event_count'   => count( $events ),
			'start_time'    => $start_time,
			'end_time'      => $end_time,
			'busiest_hour'  => self::busiest_hour( $weekly ),
			'schedules'     => $schedules,
			'event_schedules' => $event_schedules,
			'weekly'        => $weekly,
			'hours'         => $hours,
			'non_recurring' => $non_recurring,
			'outside_range' => $outside_range,
		);
	}

	/**
	 * Builds the ordered list of schedule buckets for the weekly view.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	private static function schedules(): array {
		$list = array();

		foreach ( \Crontrol\Schedule\get() as $schedule ) {
			$list[] = array(
				'slug'  => $schedule->name,
				'label' => ( '' !== $schedule->display ) ? $schedule->display : $schedule->name,
			);
		}

		$list[] = array(
			'slug'  => self::NONRECURRING,
			'label' => __( 'Non-recurring', 'wp-crontrol' ),
		);

		return $list;
	}

	/**
	 * Determines whether an event recurs on a fixed interval within the window.
	 *
	 * Mirrors {@see \Crontrol\Event\Event::is_recurring()} but also requires a positive interval,
	 * since the occurrence projection divides by it.
	 *
	 * @param \Crontrol\Event\Event $event The event.
	 */
	private static function is_recurring( \Crontrol\Event\Event $event ): bool {
		return $event->is_recurring() && null !== $event->interval && $event->interval > 0;
	}

	/**
	 * Generates every occurrence of an event within the projection window.
	 *
	 * Recurring events are projected backwards to the first occurrence within the window
	 * and then forwards to the end of the window.
	 *
	 * @param \Crontrol\Event\Event $event      The event.
	 * @param int                   $start_time The inclusive start of the window, as a Unix timestamp.
	 * @param int                   $end_time   The exclusive end of the window, as a Unix timestamp.
	 * @return array<int,int> A list of Unix timestamps within the window.
	 */
	private static function occurrences( \Crontrol\Event\Event $event, int $start_time, int $end_time ): array {
		$occurrences = array();
		$next_run = $event->timestamp;

		if ( ! self::is_recurring( $event ) ) {
			if ( $next_run >= $start_time && $next_run < $end_time ) {
				$occurrences[] = $next_run;
			}

			return $occurrences;
		}

		$interval = (int) $event->interval;

		if ( $next_run >= $start_time ) {
			// Step backwards to the latest occurrence at or before the window start.
			$steps_back = (int) ceil( ( $next_run - $start_time ) / $interval );
			$current = $next_run - ( $steps_back * $interval );
		} else {
			$current = $next_run;
		}

		// Advance to the first occurrence within the window.
		while ( $current < $start_time ) {
			$current += $interval;
		}

		while ( $current < $end_time ) {
			$occurrences[] = $current;
			$current += $interval;
		}

		return $occurrences;
	}

	/**
	 * Finds the index of the busiest hour across the weekly buckets.
	 *
	 * Ties on the total event count are broken in favour of the most varied hour (the one with
	 * the greatest number of distinct schedules firing), then the earliest hour.
	 *
	 * @param array<string,array<int,int>> $weekly The weekly buckets keyed by schedule type.
	 * @return int The 0-based hour index with the most events.
	 */
	private static function busiest_hour( array $weekly ): int {
		$busiest = 0;
		$max_total = -1;
		$max_distinct = -1;

		for ( $hour = 0; $hour < self::HOURS; $hour++ ) {
			$total = 0;
			$distinct = 0;

			foreach ( $weekly as $bucket ) {
				if ( $bucket[ $hour ] > 0 ) {
					$total += $bucket[ $hour ];
					++$distinct;
				}
			}

			if ( $total > $max_total || ( $total === $max_total && $distinct > $max_distinct ) ) {
				$max_total = $total;
				$max_distinct = $distinct;
				$busiest = $hour;
			}
		}

		return $busiest;
	}
}
