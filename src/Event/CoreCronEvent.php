<?php
/**
 * Represents a WordPress core cron event.
 */

namespace Crontrol\Event;

use Crontrol\Context\UserContext;
use Crontrol\Context\FeatureContext;

use function Crontrol\get_persistent_core_hooks;
use function Crontrol\json_output;

/**
 * Represents a WordPress core cron event.
 */
final class CoreCronEvent extends Event {
	#[\Override]
	public function hook_name_editable(): bool {
		return false;
	}

	/**
	 * Core events are persistent if they're in the persistent core hooks list.
	 */
	#[\Override]
	public function persistent(): bool {
		return in_array( $this->hook, get_persistent_core_hooks(), true );
	}

	/**
	 * Gets the message explaining why this event is persistent.
	 *
	 * @return string The persistent reason message.
	 */
	#[\Override]
	public function get_persistent_message(): string {
		return __( 'This is a WordPress core event and cannot be deleted', 'wp-crontrol' );
	}

	#[\Override]
	public function editable( UserContext $user, FeatureContext $features ): bool {
		return $user->can_edit_standard_cron_events();
	}

	#[\Override]
	public function runnable( UserContext $user, FeatureContext $features ): bool {
		return $user->can_run_standard_cron_events()
			&& ! $this->has_error()
			&& ! $this->is_paused();
	}

	/**
	 * Check if this event can be deleted.
	 *
	 * Persistent core events cannot be deleted. Non-persistent core events
	 * can be deleted if the user has manage_options capability.
	 *
	 * @param UserContext $user User capability context.
	 * @param FeatureContext $features Feature flag context (not used).
	 */
	#[\Override]
	public function deletable( UserContext $user, FeatureContext $features ): bool {
		if ( $this->persistent() ) {
			return false;
		}

		return $user->can_delete_standard_cron_events();
	}

	#[\Override]
	public function pausable(): bool {
		return true;
	}

	#[\Override]
	public function get_args_display(): string {
		if ( empty( $this->args ) ) {
			return '';
		}
		return json_output( $this->args, false );
	}

	#[\Override]
	public function is_enabled( FeatureContext $features ): bool {
		return true;
	}
}
