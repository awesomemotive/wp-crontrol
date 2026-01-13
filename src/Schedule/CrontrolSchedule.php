<?php
/**
 * Represents a custom cron schedule added by WP Crontrol.
 */

namespace Crontrol\Schedule;

/**
 * Represents a custom cron schedule added by WP Crontrol.
 */
final class CrontrolSchedule extends Schedule {
	/**
	 * Crontrol schedules are persistent if they're in use.
	 */
	#[\Override]
	public function persistent(): bool {
		return $this->is_in_use();
	}

	/**
	 * Gets the message explaining why this schedule is persistent.
	 *
	 * @return string The persistent reason message.
	 */
	#[\Override]
	public function get_persistent_message(): string {
		return __( 'This custom schedule is in use and cannot be deleted', 'wp-crontrol' );
	}

	/**
	 * Check if this schedule can be deleted.
	 *
	 * WP Crontrol schedules can be deleted if they're not in use.
	 */
	#[\Override]
	public function deletable(): bool {
		return ! $this->is_in_use();
	}
}
