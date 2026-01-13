<?php
/**
 * Feature context where PHP cron events are disabled.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

/**
 * Feature context where PHP cron events are disabled.
 */
class PHPCronsDisabledFeatureContext extends TestFeatureContext {
	#[\Override]
	public function php_crons_enabled(): bool {
		return false;
	}
}
