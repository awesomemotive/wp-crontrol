<?php
/**
 * Represents a URL cron event.
 */

namespace Crontrol\Event;

use Crontrol\Context\UserContext;
use Crontrol\Context\FeatureContext;

/**
 * Represents a URL cron event.
 */
final class URLCronEvent extends CrontrolEvent {
	/**
	 * The hook name for URL cron events.
	 */
	public const HOOK_NAME = 'crontrol_url_cron_job';

	#[\Override]
	public function integrity_failed(): bool {
		$args = $this->args[0] ?? array();
		return ! check_integrity( $args['url'] ?? null, $args['hash'] ?? null );
	}

	#[\Override]
	public function has_error(): bool {
		if ( isset( $this->args[0]['url_error_message'] ) ) {
			return true;
		}

		return $this->integrity_failed();
	}

	#[\Override]
	public function editable( UserContext $user, FeatureContext $features ): bool {
		return $features->url_crons_enabled() && $user->can_edit_url_cron_events();
	}

	#[\Override]
	public function runnable( UserContext $user, FeatureContext $features ): bool {
		return $features->url_crons_enabled()
			&& $user->can_run_url_cron_events()
			&& ! $this->has_error()
			&& ! $this->is_paused();
	}

	/**
	 * Check if this event can be deleted.
	 *
	 * Per caps.md: Feature flag is NOT checked for delete operations.
	 *
	 * @param UserContext $user User capability context.
	 * @param FeatureContext $features Feature flag context (not used for delete).
	 */
	#[\Override]
	public function deletable( UserContext $user, FeatureContext $features ): bool {
		return $user->can_delete_url_cron_events();
	}

	#[\Override]
	public function get_args_display(): string {
		return $this->args[0]['method'] . ' ' . $this->args[0]['url'];
	}

	#[\Override]
	public function is_enabled( FeatureContext $features ): bool {
		return $features->url_crons_enabled();
	}
}
