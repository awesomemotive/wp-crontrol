<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;

class EventTimeTest extends Test {
	/**
	 * Data provider for timezone test cases
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function provideTimezoneTestCases(): array {
		return array(
			'UTC timezone' => array(
				'timezone_string' => 'UTC',
				'gmt_offset' => null,
				'expected_utc' => '2024-06-15 14:30:00',
				'expected_local' => '2024-06-15 14:30:00',
			),
			'New York timezone (EDT in June, UTC-4)' => array(
				'timezone_string' => 'America/New_York',
				'gmt_offset' => null,
				'expected_utc' => '2024-06-15 14:30:00',
				'expected_local' => '2024-06-15 10:30:00',
			),
			'Tokyo timezone (UTC+9)' => array(
				'timezone_string' => 'Asia/Tokyo',
				'gmt_offset' => null,
				'expected_utc' => '2024-06-15 14:30:00',
				'expected_local' => '2024-06-15 23:30:00',
			),
			'Offset-based timezone (UTC+5:30, like India)' => array(
				'timezone_string' => '',
				'gmt_offset' => 5.5,
				'expected_utc' => '2024-06-15 14:30:00',
				'expected_local' => '2024-06-15 20:00:00',
			),
		);
	}

	/**
	 * @dataProvider provideTimezoneTestCases
	 * @covers \Crontrol\Event\Event::get_next_run_utc
	 * @covers \Crontrol\Event\Event::get_next_run_local
	 */
	public function testNextRunLocalAndUTC( string $timezone_string, ?float $gmt_offset, string $expected_utc, string $expected_local ): void {
		// Use a fixed timestamp for consistent testing
		$timestamp = strtotime( '2024-06-15 14:30:00 GMT' );
		$event = Event::create( 'test_timezone', $timestamp, 'tz_sig', array(), null, null );

		// Set up the timezone
		update_option( 'timezone_string', $timezone_string );
		if ( $gmt_offset !== null ) {
			update_option( 'gmt_offset', $gmt_offset );
		}

		// Test UTC and local times
		$utc_time = $event->get_next_run_utc( 'Y-m-d H:i:s' );
		$local_time = $event->get_next_run_local( 'Y-m-d H:i:s' );

		self::assertSame( $expected_utc, $utc_time, 'UTC time should be correct' );
		self::assertSame( $expected_local, $local_time, 'Local time should be correct' );
	}

	/**
	 * @covers \Crontrol\Event\Event::is_late
	 */
	public function testIsLateThresholdDetection(): void {
		$current_time = time();

		// Event scheduled for exactly now - not late
		$event_now = Event::create( 'test_now', $current_time, 'now_sig', array(), null, null );
		self::assertFalse( $event_now->is_late(), 'Event scheduled for now should not be late' );

		// Event scheduled for 5 minutes ago - not late (within threshold)
		$event_5_min_ago = Event::create(
			'test_5_min_ago',
			$current_time - ( 5 * MINUTE_IN_SECONDS ),
			'5min_sig',
			array(),
			null,
			null
		);
		self::assertFalse( $event_5_min_ago->is_late(), 'Event 5 minutes past should not be late' );

		// Event scheduled for exactly 10 minutes ago - not late (at threshold boundary)
		$event_10_min_ago = Event::create(
			'test_10_min_ago',
			$current_time - ( 10 * MINUTE_IN_SECONDS ),
			'10min_sig',
			array(),
			null,
			null
		);
		self::assertFalse( $event_10_min_ago->is_late(), 'Event exactly 10 minutes past should not be late' );

		// Event scheduled for 10 minutes and 1 second ago - late
		$event_10_min_1_sec_ago = Event::create(
			'test_10_min_1_sec_ago',
			$current_time - ( 10 * MINUTE_IN_SECONDS ) - 1,
			'10min1s_sig',
			array(),
			null,
			null
		);
		self::assertTrue( $event_10_min_1_sec_ago->is_late(), 'Event 10 minutes and 1 second past should be late' );

		// Event scheduled for the future - not late
		$event_future_5_min = Event::create(
			'test_future_5_min',
			$current_time + ( 5 * MINUTE_IN_SECONDS ),
			'future5min_sig',
			array(),
			null,
			null
		);
		self::assertFalse( $event_future_5_min->is_late(), 'Future event should not be late' );
	}

	/**
	 * Test DST transition handling (spring forward)
	 *
	 * @covers \Crontrol\Event\Event::create
	 * @covers \Crontrol\Event\Event::get_next_run_local
	 */
	public function testDSTSpringForward(): void {
		update_option( 'timezone_string', 'America/New_York' );

		// March 10, 2024, 2:00 AM EST -> 3:00 AM EDT (spring forward)
		$dst_spring = strtotime( '2024-03-10 07:00:00 GMT' ); // 2:00 AM EST becomes 3:00 AM EDT
		$event_spring = Event::create( 'test_dst_spring', $dst_spring, 'dst_spring_sig', array(), null, null );
		$local_spring = $event_spring->get_next_run_local( 'Y-m-d H:i:s T' );
		self::assertSame( '2024-03-10 03:00:00 EDT', $local_spring, 'Should show EDT after DST transition' );
	}

	/**
	 * Test DST transition handling (fall back)
	 *
	 * @covers \Crontrol\Event\Event::create
	 * @covers \Crontrol\Event\Event::get_next_run_local
	 */
	public function testDSTFallBack(): void {
		update_option( 'timezone_string', 'America/New_York' );

		// November 3, 2024, 2:00 AM EDT becomes 1:00 AM EST (fall back)
		// At 7:00 GMT, it's 2:00 AM EST (after the transition)
		$dst_fall = strtotime( '2024-11-03 07:00:00 GMT' ); // 2:00 AM EST
		$event_fall = Event::create( 'test_dst_fall', $dst_fall, 'dst_fall_sig', array(), null, null );
		$local_fall = $event_fall->get_next_run_local( 'Y-m-d H:i:s T' );
		self::assertSame( '2024-11-03 02:00:00 EST', $local_fall, 'Should show EST after DST fall back' );
	}

	/**
	 * @covers \Crontrol\Event\Event::is_recurring
	 */
	public function testRecurringEventTimeCalculations(): void {
		$recurring_event = Event::create(
			'test_recurring_time',
			time(),
			'recurring_sig',
			array(),
			'daily',
			DAY_IN_SECONDS
		);

		self::assertTrue( $recurring_event->is_recurring(), 'Should be identified as recurring' );
	}
}
