<?php
/**
 * Base test user context with all permissions enabled.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

use Crontrol\Context\UserContext;

/**
 * Base test user context with all permissions enabled.
 *
 * This is the default "everything works" context that other test contexts
 * can extend and override specific methods.
 */
class TestUserContext implements UserContext {
	#[\Override]
	public function can_create_php_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_edit_php_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_delete_php_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_run_php_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_create_url_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_edit_url_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_delete_url_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_run_url_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_create_standard_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_edit_standard_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_delete_standard_cron_events(): bool {
		return true;
	}

	#[\Override]
	public function can_run_standard_cron_events(): bool {
		return true;
	}
}
