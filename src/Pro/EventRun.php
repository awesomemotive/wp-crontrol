<?php

namespace Crontrol\Pro;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Represents a cron event run with its execution details.
 */
final class EventRun implements Item {
	public const STATUS_RUNNING = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_ERRORED = 'errored';

	/**
	 * Fraction of a recurring schedule's interval at or beyond which a run is flagged
	 * as having approached the interval.
	 */
	public const NEAR_INTERVAL_THRESHOLD = 0.75;

	/**
	 * Event run ID.
	 */
	public int $ID = 0;

	/**
	 * Site ID.
	 */
	public int $site_id;

	/**
	 * Hook name.
	 */
	public string $hook;

	/**
	 * Actions executed.
	 *
	 * Names of the callbacks registered on the hook.
	 *
	 * @var list<string>
	 */
	public array $actions = [];

	/**
	 * Schedule name.
	 */
	public string $schedule;

	/**
	 * Recurrence interval of the schedule, in seconds.
	 *
	 * Zero for one-time events or schedules with no known interval. Named with the
	 * unit suffix to avoid the reserved `interval` keyword as a database column.
	 */
	public int $interval_seconds = 0;

	/**
	 * Event arguments.
	 *
	 * @var array<int, mixed>
	 */
	public array $args;

	/**
	 * @phpstan-var self::STATUS_*
	 */
	public string $status = self::STATUS_RUNNING;

	/**
	 * Start time.
	 */
	public DateTimeImmutable $started_at;

	/**
	 * Scheduled time.
	 */
	public DateTimeImmutable $scheduled_at;

	/**
	 * Duration in milliseconds.
	 */
	public int $duration = 0;

	/**
	 * Whether a fatal error occurred.
	 */
	public bool $fatal = false;

	/**
	 * Whether warnings occurred.
	 */
	public bool $warning = false;

	/**
	 * HTTPS requests count.
	 */
	public int $https = 0;

	/**
	 * Emails sent count.
	 */
	public int $emails = 0;

	/**
	 * Count of emails that failed to send (WordPress fired `wp_mail_failed`).
	 */
	public int $mail_failed = 0;

	/**
	 * Memory usage in bytes.
	 */
	public int $memory = 0;

	/**
	 * Database queries count.
	 */
	public int $db_queries = 0;

	/**
	 * Error messages.
	 *
	 * Holds both fatal errors and error-level messages from the logger. Each entry is an
	 * associative array containing the log level and message.
	 *
	 * @var list<array{
	 *   level: string,
	 *   message: string,
	 * }>
	 */
	public array $errors = [];

	/**
	 * Log messages.
	 *
	 * Holds non-error log messages from the logger. Error-level messages are stored
	 * separately in $errors. Each entry is an associative array containing the log
	 * level and message.
	 *
	 * @var list<array{
	 *   level: string,
	 *   message: string,
	 * }>
	 */
	public array $logs = [];

	/**
	 * Start tracking an event run.
	 *
	 * @todo there's nothing in WP core that passes the scheduled time to a cron event
	 * @see https://core.trac.wordpress.org/query?status=!closed&component=Cron+API
	 * @see https://core.trac.wordpress.org/ticket/28635
	 *
	 * @param string $hook Hook name.
	 * @param string $schedule Schedule name.
	 * @param int $scheduled_at Scheduled timestamp.
	 * @param array<int, mixed> $args Event arguments.
	 * @return self
	 */
	public static function start( string $hook, string $schedule, int $scheduled_at, array $args ): self {
		$item = new self();
		$item->site_id = get_current_blog_id();
		$item->hook = $hook;
		$item->schedule = $schedule;
		$item->interval_seconds = self::get_schedule_interval( $schedule );
		$item->started_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$item->scheduled_at = new DateTimeImmutable( "@{$scheduled_at}", new DateTimeZone( 'UTC' ) );
		$item->args = $args;

		$actions = array_map( static function ( array $item ): string {
			return $item['name'] ?? '';
		}, wp_list_pluck( \Crontrol\get_hook_callbacks( $hook ), 'callback' ) );

		$item->actions = array_values( array_filter( $actions ) );

		$item->warning = true;

		return $item;
	}

	/**
	 * Finish tracking an event run.
	 *
	 * @param string $hook Hook name.
	 * @param string $schedule Schedule name.
	 * @param int $scheduled_at Scheduled timestamp.
	 * @param array<int, mixed> $args Event arguments.
	 *
	 * @throws \RuntimeException Always, as this method is not implemented.
	 */
	public static function finish( string $hook, string $schedule, int $scheduled_at, array $args ): self {
		throw new \RuntimeException( 'Not implemented' );
	}

	/**
	 * Stop the event run and calculate duration.
	 */
	private function stop(): self {
		$stopped = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$diff = $stopped->diff( $this->started_at );

		// Set the duration in milliseconds:
		// Calculate total seconds including minutes, hours, etc.
		$total_seconds = $diff->s + ( $diff->i * 60 ) + ( $diff->h * 3600 ) + ( $diff->d * 86400 );
		// Convert to milliseconds and add microseconds converted to milliseconds
		$this->duration = (int) ( ( $total_seconds * 1000 ) + ( $diff->f * 1000 ) );
		return $this;
	}

	/**
	 * Resolves the recurrence interval in seconds for a named schedule.
	 *
	 * Returns zero for one-time events or schedules with no known interval.
	 *
	 * @param string $schedule Schedule name.
	 */
	private static function get_schedule_interval( string $schedule ): int {
		if ( '' === $schedule ) {
			return 0;
		}

		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $schedule ]['interval'] ) ) {
			return 0;
		}

		return (int) $schedules[ $schedule ]['interval'];
	}

	/**
	 * Whether the run duration approached the recurrence interval of its schedule.
	 *
	 * Only applies to recurring events. True when the run took at least
	 * NEAR_INTERVAL_THRESHOLD of the interval, indicating it risks overlapping or
	 * delaying the next scheduled run. Remains true even once the interval is exceeded.
	 */
	public function approached_interval(): bool {
		if ( $this->interval_seconds <= 0 ) {
			return false;
		}

		return $this->duration >= ( $this->interval_seconds * 1000 * self::NEAR_INTERVAL_THRESHOLD );
	}

	/**
	 * Whether the run duration exceeded the recurrence interval of its schedule.
	 *
	 * Only applies to recurring events. True when the run took longer than the full
	 * interval, meaning it overran into the window of the next scheduled run.
	 */
	public function exceeded_interval(): bool {
		if ( $this->interval_seconds <= 0 ) {
			return false;
		}

		return $this->duration >= ( $this->interval_seconds * 1000 );
	}

	/**
	 * Mark the event as completed.
	 */
	public function mark_completed(): self {
		$this->stop()->status = self::STATUS_COMPLETED;
		return $this;
	}

	/**
	 * Mark the event as errored.
	 */
	public function mark_errored(): self {
		$this->stop()->status = self::STATUS_ERRORED;
		return $this;
	}

	/**
	 * Check if the event is finished.
	 */
	public function is_finished(): bool {
		return $this->status !== self::STATUS_RUNNING;
	}

	/**
	 * Check if the event completed successfully.
	 */
	public function is_completed(): bool {
		return $this->status === self::STATUS_COMPLETED;
	}

	/**
	 * Check if the event errored.
	 */
	public function is_errored(): bool {
		return $this->status === self::STATUS_ERRORED;
	}

	/**
	 * Check if the event is still running.
	 */
	public function is_running(): bool {
		return $this->status === self::STATUS_RUNNING;
	}

	public function get_duration(): float {
		if ( $this->is_running() ) {
			// For running events, calculate approximate elapsed time
			$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$diff = $now->diff( $this->started_at );

			$seconds = $diff->s
				+ ( $diff->i * 60 )
				+ ( $diff->h * 3600 )
				+ ( $diff->d * 86400 );

			return $seconds * 1000;
		}

		return $this->duration;
	}
}
