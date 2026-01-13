<?php
/**
 * Base class for cron events.
 */

namespace Crontrol\Event;

use Crontrol\Context\FeatureContext;
use Crontrol\Context\UserContext;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;
use Crontrol\Event\CoreCronEvent;
use Crontrol\Event\ActionSchedulerEvent;
use Crontrol\Event\StandardEvent;
use Crontrol\Exception\UnknownScheduleException;

/**
 * Base class for cron events.
 */
abstract class Event {
	/**
	 * The hook name of the cron event.
	 *
	 * @var string
	 */
	public string $hook;

	/**
	 * The Unix timestamp when the event should run.
	 *
	 * @var int
	 */
	public int $timestamp;

	/**
	 * The event signature.
	 *
	 * @var string
	 */
	public string $sig;

	/**
	 * The arguments to pass to the hook's callback function.
	 *
	 * @var mixed[]
	 */
	public array $args;

	/**
	 * The schedule name or null for one-time events.
	 *
	 * @var string|null
	 */
	public $schedule;

	/**
	 * The interval time in seconds for the schedule. Only present for recurring events.
	 *
	 * @var int|null
	 */
	public $interval;

	/**
	 * Constructor.
	 *
	 * @param string      $hook The hook name of the cron event.
	 * @param int         $timestamp The Unix timestamp (UTC) when the event should run.
	 * @param string      $sig The event signature.
	 * @param mixed[]     $args The arguments to pass to the hook's callback function.
	 * @param string|null $schedule The schedule name or null for one-time events.
	 * @param int|null    $interval The interval time in seconds for the schedule. Only present for recurring events.
	 */
	protected function __construct( string $hook, int $timestamp, string $sig, array $args, ?string $schedule, ?int $interval ) {
		$this->hook = $hook;
		$this->timestamp = $timestamp;
		$this->sig = $sig;
		$this->args = $args;
		$this->schedule = $schedule;
		$this->interval = $interval;
	}

	/**
	 * Factory method to create appropriate Event instance.
	 *
	 * @param string      $hook The hook name of the cron event.
	 * @param int         $timestamp The Unix timestamp (UTC) when the event should run.
	 * @param string      $sig The event signature.
	 * @param mixed[]     $args The arguments to pass to the hook's callback function.
	 * @param string|null $schedule The schedule name or null for one-time events.
	 * @param int|null    $interval The interval time in seconds for the schedule. Only present for recurring events.
	 * @return self The appropriate Event instance.
	 * @phpstan-return (
	 *   $hook is PHPCronEvent::HOOK_NAME ? PHPCronEvent :
	 *   $hook is URLCronEvent::HOOK_NAME ? URLCronEvent :
	 *   $hook is ActionSchedulerEvent::HOOK_NAME ? ActionSchedulerEvent :
	 *   (CoreCronEvent|StandardEvent)
	 * )
	 */
	public static function create( string $hook, int $timestamp, string $sig, array $args, ?string $schedule, ?int $interval ): self {
		if ( PHPCronEvent::HOOK_NAME === $hook ) {
			return new PHPCronEvent( $hook, $timestamp, $sig, $args, $schedule, $interval );
		}

		if ( URLCronEvent::HOOK_NAME === $hook ) {
			return new URLCronEvent( $hook, $timestamp, $sig, $args, $schedule, $interval );
		}

		if ( ActionSchedulerEvent::HOOK_NAME === $hook ) {
			return new ActionSchedulerEvent( $hook, $timestamp, $sig, $args, $schedule, $interval );
		}

		if ( in_array( $hook, \Crontrol\get_all_core_hooks(), true ) ) {
			return new CoreCronEvent( $hook, $timestamp, $sig, $args, $schedule, $interval );
		}

		return new StandardEvent( $hook, $timestamp, $sig, $args, $schedule, $interval );
	}

	/**
	 * Factory method to create a new empty Event instance with default values.
	 *
	 * @return self A new StandardEvent instance with default empty values.
	 */
	public static function create_new(): self {
		return self::create( '', time(), '', array(), null, null );
	}

	/**
	 * Factory method to create an immediate Event instance (timestamp = 1).
	 *
	 * @param string  $hook The hook name of the cron event.
	 * @param mixed[] $args The arguments to pass to the hook's callback function.
	 * @return self The appropriate Event instance set to run immediately.
	 */
	public static function create_immediate( string $hook, array $args = array() ): self {
		return self::create( $hook, 1, '', $args, null, null );
	}

	/**
	 * Check if this is a recurring event.
	 */
	public function is_recurring(): bool {
		return is_string( $this->schedule );
	}

	/**
	 * Get the registered callbacks for this event's hook.
	 *
	 * @return array<int,array<string,mixed>> Array of callbacks attached to the hook.
	 * @phpstan-return array<int,array{
	 *   priority: int,
	 *   callback: array<string,mixed>,
	 * }>
	 */
	public function get_callbacks(): array {
		return \Crontrol\get_hook_callbacks( $this->hook );
	}

	/**
	 * Get the next run time in local timezone.
	 *
	 * @param string $format The date format string. Defaults to 'c' (ISO 8601).
	 * @return string The formatted date in local timezone.
	 */
	public function get_next_run_local( string $format = 'c' ): string {
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $this->timestamp ), $format );
	}

	/**
	 * Get the next run time in UTC.
	 *
	 * @param string $format The date format string. Defaults to 'c' (ISO 8601).
	 * @return string The formatted date in UTC.
	 */
	public function get_next_run_utc( string $format = 'c' ): string {
		return gmdate( $format, $this->timestamp );
	}

	/**
	 * Check if this event's hook is paused.
	 */
	public function is_paused(): bool {
		$paused = get_option( \Crontrol\PAUSED_OPTION );

		if ( ! is_array( $paused ) ) {
			return false;
		}

		return array_key_exists( $this->hook, $paused );
	}

	/**
	 * Check if this event is late (past its scheduled time by more than 10 minutes).
	 */
	public function is_late(): bool {
		$until = $this->timestamp - time();

		return ( $until < ( 0 - ( 10 * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Check if this event's schedule is too frequent (interval less than WP_CRON_LOCK_TIMEOUT).
	 */
	public function is_too_frequent(): bool {
		if ( ! $this->schedule ) {
			return false;
		}

		$schedules = \Crontrol\Schedule\get();

		if ( ! isset( $schedules[ $this->schedule ] ) ) {
			return false;
		}

		return $schedules[ $this->schedule ]->is_too_frequent();
	}

	/**
	 * Check if this event has integrity failures (corrupted data).
	 */
	public function integrity_failed(): bool {
		return false;
	}

	/**
	 * Check if this event has any errors (syntax errors, URL errors, or integrity failures).
	 */
	public function has_error(): bool {
		return false;
	}

	/**
	 * Get the schedule name for this event.
	 *
	 * @return string The schedule display name.
	 * @throws UnknownScheduleException If schedule is unknown.
	 */
	public function get_schedule_name(): string {
		if ( ! $this->is_recurring() ) {
			return __( 'Non-repeating', 'wp-crontrol' );
		}

		$schedules = \Crontrol\Schedule\get();

		if ( isset( $schedules[ $this->schedule ] ) ) {
			return $schedules[ $this->schedule ]->display;
		}

		throw new UnknownScheduleException(
			sprintf(
				/* translators: %s: Schedule name */
				__( 'Unknown schedule (%s)', 'wp-crontrol' ),
				$this->schedule
			)
		);
	}

	/**
	 * Check if this event's hook name can be edited.
	 */
	public function hook_name_editable(): bool {
		return true;
	}

	/**
	 * Check if this event is scheduled to run immediately via "Run now".
	 *
	 * Events with timestamp 1 are scheduled to run immediately and only appear
	 * in the event list when there's a problem with the event runner.
	 */
	public function is_immediate(): bool {
		return $this->timestamp === 1;
	}

	/**
	 * Determines if this event can be edited given the current user and feature context.
	 *
	 * @param UserContext $user User capability context.
	 * @param FeatureContext $features Feature flag context.
	 */
	abstract public function editable( UserContext $user, FeatureContext $features ): bool;

	/**
	 * Determines if this event can be run given the current user and feature context.
	 *
	 * @param UserContext $user User capability context.
	 * @param FeatureContext $features Feature flag context.
	 */
	abstract public function runnable( UserContext $user, FeatureContext $features ): bool;

	/**
	 * Determines if this event is persistent and cannot be deleted regardless of permissions.
	 */
	public function persistent(): bool {
		return false;
	}

	/**
	 * Gets the message explaining why this event is persistent.
	 *
	 * Only called if persistent() returns true.
	 *
	 * @return string The persistent reason message.
	 */
	public function get_persistent_message(): string {
		return '';
	}

	/**
	 * Determines if this event can be deleted given the current user and feature context.
	 *
	 * @param UserContext $user User capability context.
	 * @param FeatureContext $features Feature flag context.
	 */
	abstract public function deletable( UserContext $user, FeatureContext $features ): bool;

	/**
	 * Determines if this event can be paused.
	 */
	abstract public function pausable(): bool;

	/**
	 * Gets the display representation of this event's arguments.
	 *
	 * @return string The formatted arguments for display.
	 */
	abstract public function get_args_display(): string;

	/**
	 * Determines if this event type is currently enabled in the feature context.
	 *
	 * @param FeatureContext $features Feature flag context.
	 */
	abstract public function is_enabled( FeatureContext $features ): bool;
}
