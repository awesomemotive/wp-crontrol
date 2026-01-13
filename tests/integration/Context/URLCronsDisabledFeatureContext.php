<?php
/**
 * Feature context where URL cron events are disabled.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

/**
 * Feature context where URL cron events are disabled.
 */
class URLCronsDisabledFeatureContext extends TestFeatureContext {
	#[\Override]
	public function url_crons_enabled(): bool {
		return false;
	}
}
