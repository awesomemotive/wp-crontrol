<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Event\PHPCronEvent;

class EventIntegrityTest extends Test {
	/**
	 * Test handling of pre-1.16.2 PHP cron events (with args['code'] structure)
	 *
	 * @covers \Crontrol\Event\PHPCronEvent::integrity_failed
	 * @covers \Crontrol\Event\PHPCronEvent::has_error
	 */
	public function testLegacyPHPCronEventHandling(): void {
		$hook = PHPCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;
		$sig = 'legacy_php_event';

		// Create event with pre-1.16.2 structure (code in root of args)
		$args = array(
			'code' => 'echo "legacy code";',
			'name' => 'Legacy PHP Job',
		);
		$event = Event::create( $hook, $timestamp, $sig, $args, null, null );

		// The integrity check should fail for legacy structure
		self::assertTrue( $event->integrity_failed(), 'Legacy PHP event structure should trigger integrity failure' );
		self::assertTrue( $event->has_error(), 'Legacy PHP event should have error due to integrity failure' );
	}
}
