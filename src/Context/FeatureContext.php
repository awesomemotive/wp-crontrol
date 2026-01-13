<?php
/**
 * Feature flag context for WP Crontrol.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Context;

/**
 * Interface for checking feature flags.
 *
 * Provides methods to check if various cron event features are enabled.
 */
interface FeatureContext {
	/**
	 * Check whether PHP cron events are enabled globally on the site.
	 */
	public function php_crons_enabled(): bool;

	/**
	 * Check whether URL cron events are enabled globally on the site.
	 */
	public function url_crons_enabled(): bool;
}
