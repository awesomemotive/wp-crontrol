<?php
/**
 * User context where user has no permissions.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

/**
 * User context where user has no permissions to create/edit/delete PHP/URL cron events.
 *
 * Note: User can still RUN events and can create/edit/delete/run standard events.
 */
class NoPermissionsUserContext extends TestUserContext {
	#[\Override]
	public function can_create_php_cron_events(): bool {
		return false;
	}

	#[\Override]
	public function can_edit_php_cron_events(): bool {
		return false;
	}

	#[\Override]
	public function can_delete_php_cron_events(): bool {
		return false;
	}

	#[\Override]
	public function can_create_url_cron_events(): bool {
		return false;
	}

	#[\Override]
	public function can_edit_url_cron_events(): bool {
		return false;
	}

	#[\Override]
	public function can_delete_url_cron_events(): bool {
		return false;
	}
}
