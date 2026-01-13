<?php
/**
 * Represents an Action Scheduler cron event.
 */

namespace Crontrol\Event;

use Crontrol\Context\UserContext;
use Crontrol\Context\FeatureContext;

use function Crontrol\json_output;

/**
 * Represents an Action Scheduler cron event.
 */
final class ActionSchedulerEvent extends Event {
	/**
	 * The hook name for Action Scheduler events.
	 */
	public const HOOK_NAME = 'action_scheduler_run_queue';

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

	#[\Override]
	public function deletable( UserContext $user, FeatureContext $features ): bool {
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
