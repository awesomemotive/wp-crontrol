<?php
/**
 * Represents a cron schedule added by another plugin or theme.
 */

namespace Crontrol\Schedule;

/**
 * Represents a cron schedule added by another plugin or theme.
 */
final class ThirdPartySchedule extends Schedule {
	/**
	 * Third-party schedules are persistent.
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
		return __( 'This schedule is added by another plugin and cannot be deleted', 'wp-crontrol' );
	}

	/**
	 * Check if this schedule can be deleted.
	 *
	 * Third-party schedules cannot be deleted.
	 */
	#[\Override]
	public function deletable(): bool {
		return false;
	}
}
