<?php
/**
 * User capability context for WP Crontrol.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Context;

/**
 * Interface for checking user capabilities.
 *
 * Provides methods to check if the current user has permission to perform
 * various operations on different types of cron events.
 */
interface UserContext {
	/**
	 * Check if the user can create PHP cron events.
	 */
	public function can_create_php_cron_events(): bool;

	/**
	 * Check if the user can edit PHP cron events.
	 */
	public function can_edit_php_cron_events(): bool;

	/**
	 * Check if the user can delete PHP cron events.
	 */
	public function can_delete_php_cron_events(): bool;

	/**
	 * Check if the user can run PHP cron events.
	 */
	public function can_run_php_cron_events(): bool;

	/**
	 * Check if the user can create URL cron events.
	 */
	public function can_create_url_cron_events(): bool;

	/**
	 * Check if the user can edit URL cron events.
	 */
	public function can_edit_url_cron_events(): bool;

	/**
	 * Check if the user can delete URL cron events.
	 */
	public function can_delete_url_cron_events(): bool;

	/**
	 * Check if the user can run URL cron events.
	 */
	public function can_run_url_cron_events(): bool;

	/**
	 * Check if the user can create standard cron events.
	 */
	public function can_create_standard_cron_events(): bool;

	/**
	 * Check if the user can edit standard cron events.
	 */
	public function can_edit_standard_cron_events(): bool;

	/**
	 * Check if the user can delete standard cron events.
	 */
	public function can_delete_standard_cron_events(): bool;

	/**
	 * Check if the user can run standard cron events.
	 */
	public function can_run_standard_cron_events(): bool;
}
