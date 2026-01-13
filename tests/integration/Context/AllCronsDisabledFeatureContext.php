<?php
/**
 * Feature context where all cron event types are disabled.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

/**
 * Feature context where all cron event types are disabled.
 */
class AllCronsDisabledFeatureContext extends TestFeatureContext {
	#[\Override]
	public function php_crons_enabled(): bool {
		return false;
	}

	#[\Override]
	public function url_crons_enabled(): bool {
		return false;
	}
}
