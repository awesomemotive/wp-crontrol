<?php
/**
 * Base test feature context with all features enabled.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Tests\Context;

use Crontrol\Context\FeatureContext;

/**
 * Base test feature context with all features enabled.
 */
class TestFeatureContext implements FeatureContext {
	#[\Override]
	public function php_crons_enabled(): bool {
		return true;
	}

	#[\Override]
	public function url_crons_enabled(): bool {
		return true;
	}
}
