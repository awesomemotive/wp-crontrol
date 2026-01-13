<?php
/**
 * Base class for WP Crontrol managed events (PHP and URL cron events).
 */

namespace Crontrol\Event;

/**
 * Abstract base class for WP Crontrol managed events.
 *
 * Provides common logic for PHP and URL cron events.
 */
abstract class CrontrolEvent extends Event {
	#[\Override]
	final public function hook_name_editable(): bool {
		return false;
	}

	#[\Override]
	final public function pausable(): bool {
		return false;
	}
}
