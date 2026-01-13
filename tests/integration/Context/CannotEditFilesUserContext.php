<?php
/**
 * User context where user cannot edit files.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

/**
 * User context where user cannot edit files (no edit_files capability).
 */
class CannotEditFilesUserContext extends TestUserContext {
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
}
