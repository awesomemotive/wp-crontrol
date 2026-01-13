<?php
/**
 * Represents a WordPress core cron schedule.
 */

namespace Crontrol\Schedule;

/**
 * Represents a WordPress core cron schedule.
 */
final class CoreSchedule extends Schedule {
	/**
	 * Core schedules are persistent.
	 */
	#[\Override]
	public function persistent(): bool {
		return true;
	}

	/**
	 * Gets the message explaining why this schedule is persistent.
	 *
	 * @return string The persistent reason message.
	 */
	#[\Override]
	public function get_persistent_message(): string {
		return __( 'This is a WordPress core schedule and cannot be deleted', 'wp-crontrol' );
	}

	/**
	 * Check if this schedule can be deleted.
	 *
	 * Core schedules cannot be deleted.
	 */
	#[\Override]
	public function deletable(): bool {
		return false;
	}
}
