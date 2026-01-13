<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Exception\UnknownScheduleException;

class EventScheduleTest extends Test {
	/**
	 * @covers \Crontrol\Event\Event::get_schedule_name
	 */
	public function testGetScheduleNameHandlesUnknownSchedule(): void {
		self::expectException( UnknownScheduleException::class );

		$timestamp = time() + 3600;

		$event_unknown = Event::create(
			'test_schedule_unknown',
			$timestamp,
			'sig',
			array(),
			'non_existent_schedule',
			999
		);

		// This should throw the expected exception
		$event_unknown->get_schedule_name();
	}
}
