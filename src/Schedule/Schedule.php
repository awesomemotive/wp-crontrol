<?php
/**
 * Base class for cron schedules.
 */

namespace Crontrol\Schedule;

/**
 * Base class for cron schedules.
 */
abstract class Schedule {
	/**
	 * The internal name of the schedule.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The interval between executions in seconds.
	 *
	 * @var int
	 */
	public int $interval;

	/**
	 * The display name of the schedule.
	 *
	 * @var string
	 */
	public string $display;

	/**
	 * Constructor.
	 *
	 * @param string $name     The internal name of the schedule.
	 * @param int    $interval The interval between executions in seconds.
	 * @param string $display  The display name of the schedule.
	 */
	protected function __construct( string $name, int $interval, string $display ) {
		$this->name = $name;
		$this->interval = $interval;
		$this->display = $display;
	}

	/**
	 * Factory method to create appropriate Schedule instance.
	 *
	 * @param string $name     The internal name of the schedule.
	 * @param int    $interval The interval between executions in seconds.
	 * @param string $display  The display name of the schedule.
	 * @phpstan-return (CoreSchedule|CrontrolSchedule|ThirdPartySchedule)
	 */
	public static function create( string $name, int $interval, string $display ): self {
		if ( in_array( $name, \Crontrol\get_core_schedules(), true ) ) {
			return new CoreSchedule( $name, $interval, $display );
		}

		/** @var array<string,int|string> */
		$custom_schedules = get_option( 'crontrol_schedules', array() );

		if ( isset( $custom_schedules[ $name ] ) ) {
			return new CrontrolSchedule( $name, $interval, $display );
		}

		return new ThirdPartySchedule( $name, $interval, $display );
	}

	/**
	 * Check if this schedule's interval is too frequent (less than WP_CRON_LOCK_TIMEOUT).
	 */
	public function is_too_frequent(): bool {
		return $this->interval < WP_CRON_LOCK_TIMEOUT;
	}

	/**
	 * Determines if this schedule is persistent and cannot be deleted.
	 */
	public function persistent(): bool {
		return false;
	}

	/**
	 * Gets the message explaining why this schedule is persistent.
	 *
	 * Only called if persistent() returns true.
	 *
	 * @return string The persistent reason message.
	 */
	public function get_persistent_message(): string {
		return '';
	}

	/**
	 * Check if this schedule can be deleted.
	 */
	public function deletable(): bool {
		return true;
	}

	/**
	 * Check if this schedule is currently in use by any events.
	 */
	public function is_in_use(): bool {
		$events = \Crontrol\Event\get();

		foreach ( $events as $event ) {
			if ( $event->schedule === $this->name ) {
				return true;
			}
		}

		return false;
	}
}
