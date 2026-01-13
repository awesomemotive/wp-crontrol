<?php
/**
 * WordPress user capability context implementation.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Context;

/**
 * UserContext implementation that checks WordPress user capabilities.
 *
 * This is the production implementation that maps to WordPress capabilities.
 * Currently uses edit_files for PHP cron events and manage_options for
 * URL and standard cron events. More granular capabilities can be added later.
 */
class WordPressUserContext implements UserContext {
	#[\Override]
	public function can_create_php_cron_events(): bool {
		return current_user_can( 'edit_files' );
	}

	#[\Override]
	public function can_edit_php_cron_events(): bool {
		return current_user_can( 'edit_files' );
	}

	#[\Override]
	public function can_delete_php_cron_events(): bool {
		return current_user_can( 'edit_files' );
	}

	#[\Override]
	public function can_run_php_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_create_url_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_edit_url_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_delete_url_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_run_url_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_create_standard_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_edit_standard_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_delete_standard_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}

	#[\Override]
	public function can_run_standard_cron_events(): bool {
		return current_user_can( 'manage_options' );
	}
}
