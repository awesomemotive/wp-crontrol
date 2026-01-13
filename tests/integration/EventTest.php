<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol;
use Crontrol\Event\Event;
use Crontrol\Event\StandardEvent;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;
use Crontrol\Event\CoreCronEvent;
use Crontrol\Event\ActionSchedulerEvent;
use Crontrol\Event\CrontrolEvent;

class EventTest extends Test {
	public function testCreatesStandardEventForUnknownHooks(): void {
		$hook = 'custom_test_hook';
		$timestamp = time() + 3600;
		$sig = 'test_sig';
		$args = array( 'test' => 'value' );
		$schedule = null;
		$interval = null;

		$event = Event::create( $hook, $timestamp, $sig, $args, $schedule, $interval );

		self::assertInstanceOf( StandardEvent::class, $event );
		self::assertSame( $hook, $event->hook );
		self::assertSame( $timestamp, $event->timestamp );
		self::assertSame( $sig, $event->sig );
		self::assertSame( $args, $event->args );
		self::assertNull( $event->schedule );
		self::assertNull( $event->interval );
	}

	public function testCreatesPHPCronEventForPHPHook(): void {
		$hook = PHPCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;
		$sig = 'php_sig';
		$args = array(
			array(
				'code' => 'echo "test";',
				'name' => 'Test PHP Job',
				'hash' => wp_hash( 'echo "test";' ),
			),
		);
		$schedule = null;
		$interval = null;

		$event = Event::create( $hook, $timestamp, $sig, $args, $schedule, $interval );

		self::assertInstanceOf( PHPCronEvent::class, $event );
		self::assertFalse( $event->hook_name_editable() );
	}

	public function testCreatesURLCronEventForURLHook(): void {
		$hook = URLCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;
		$sig = 'url_sig';
		$args = array(
			array(
				'url' => 'https://example.com/webhook',
				'name' => 'Test URL Job',
				'hash' => wp_hash( 'https://example.com/webhook' ),
			),
		);
		$schedule = 'hourly';
		$interval = 3600;

		$event = Event::create( $hook, $timestamp, $sig, $args, $schedule, $interval );

		self::assertInstanceOf( URLCronEvent::class, $event );
		self::assertFalse( $event->hook_name_editable() );
	}

	public function testCreatesCoreCronEventForCoreHook(): void {
		$hook = 'do_pings'; // A non-persistent core hook
		$timestamp = time() + 3600;
		$sig = 'core_sig';
		$args = array();
		$schedule = 'twicedaily';
		$interval = 43200;

		$event = Event::create( $hook, $timestamp, $sig, $args, $schedule, $interval );

		self::assertInstanceOf( CoreCronEvent::class, $event );
		self::assertFalse( $event->hook_name_editable() );
	}

	public function testCreatesActionSchedulerEventForActionSchedulerHook(): void {
		$hook = ActionSchedulerEvent::HOOK_NAME;
		$timestamp = time() + 300;
		$sig = 'as_sig';
		$args = array();
		$schedule = 'every_minute';
		$interval = 60;

		$event = Event::create( $hook, $timestamp, $sig, $args, $schedule, $interval );

		self::assertInstanceOf( ActionSchedulerEvent::class, $event );
		self::assertTrue( $event->hook_name_editable() );
	}

	public function testCreatesNewStandardEventWithDefaults(): void {
		$before_time = time();
		$event = Event::create_new();
		$after_time = time();

		self::assertInstanceOf( StandardEvent::class, $event );
		self::assertSame( '', $event->hook );
		self::assertGreaterThanOrEqual( $before_time, $event->timestamp );
		self::assertLessThanOrEqual( $after_time, $event->timestamp );
		self::assertSame( '', $event->sig );
		self::assertSame( array(), $event->args );
		self::assertNull( $event->schedule );
		self::assertNull( $event->interval );
	}

	public function testCreatesImmediateEvent(): void {
		$hook = 'test_immediate_hook';
		$args = array( 'key' => 'value', 'number' => 123 );

		$event = Event::create_immediate( $hook, $args );

		self::assertInstanceOf( StandardEvent::class, $event );
		self::assertSame( $hook, $event->hook );
		self::assertSame( 1, $event->timestamp );
		self::assertSame( '', $event->sig );
		self::assertSame( $args, $event->args );
		self::assertNull( $event->schedule );
		self::assertNull( $event->interval );
		self::assertTrue( $event->is_immediate() );
	}

	public function testCreatesImmediateEventWithEmptyArgs(): void {
		$hook = 'test_immediate_no_args';

		$event = Event::create_immediate( $hook );

		self::assertInstanceOf( StandardEvent::class, $event );
		self::assertSame( $hook, $event->hook );
		self::assertSame( 1, $event->timestamp );
		self::assertSame( '', $event->sig );
		self::assertSame( array(), $event->args );
		self::assertTrue( $event->is_immediate() );
	}

	public function testCreatesImmediateWithCorrectSubclassForSpecialHooks(): void {
		// Test PHP cron hook
		$php_event = Event::create_immediate( PHPCronEvent::HOOK_NAME, array( array( 'code' => 'echo "test";' ) ) );
		self::assertInstanceOf( PHPCronEvent::class, $php_event );
		self::assertTrue( $php_event->is_immediate() );

		// Test URL cron hook
		$url_event = Event::create_immediate( URLCronEvent::HOOK_NAME, array( array( 'url' => 'https://example.com' ) ) );
		self::assertInstanceOf( URLCronEvent::class, $url_event );
		self::assertTrue( $url_event->is_immediate() );

		// Test non-persistent core hook
		$core_event = Event::create_immediate( 'do_pings' );
		self::assertInstanceOf( CoreCronEvent::class, $core_event );
		self::assertTrue( $core_event->is_immediate() );
	}

	public function testIsRecurringForOneTimeVsRecurringEvents(): void {
		// Test recurring event
		$recurring_event = Event::create( 'test_recurring', time(), 'sig2', array(), 'hourly', 3600 );

		// Test one-time event
		$one_time_event = Event::create( 'test_one_time', time(), 'sig1', array(), null, null );

		// Test that create_new() creates a non-recurring event
		$new_event = Event::create_new();

		self::assertTrue( $recurring_event->is_recurring() );
		self::assertFalse( $one_time_event->is_recurring() );
		self::assertFalse( $new_event->is_recurring() );
	}

	public function testIsLateForEventsPastScheduledTime(): void {
		// Event scheduled for the future - not late
		$future_event = Event::create( 'test_future', time() + 3600, 'sig1', array(), null, null );
		self::assertFalse( $future_event->is_late() );

		// Event scheduled for 5 minutes ago - not late (within 10-minute threshold)
		$recent_event = Event::create( 'test_recent', time() - ( 5 * MINUTE_IN_SECONDS ), 'sig2', array(), null, null );
		self::assertFalse( $recent_event->is_late() );

		// Event scheduled for 15 minutes ago - late
		$late_event = Event::create( 'test_late', time() - ( 15 * MINUTE_IN_SECONDS ), 'sig3', array(), null, null );
		self::assertTrue( $late_event->is_late() );

		// Event scheduled for an hour ago - definitely late
		$very_late_event = Event::create( 'test_very_late', time() - HOUR_IN_SECONDS, 'sig4', array(), null, null );
		self::assertTrue( $very_late_event->is_late() );
	}

	public function testIsImmediateForEventsWithTimestampOne(): void {
		// Normal event - not immediate
		$normal_event = Event::create( 'test_normal', time() + 3600, 'sig1', array(), null, null );
		self::assertFalse( $normal_event->is_immediate() );

		// Immediate event with timestamp = 1
		$immediate_event = Event::create( 'test_immediate', 1, 'sig2', array(), null, null );
		self::assertTrue( $immediate_event->is_immediate() );

		// Event with timestamp = 2 - not immediate
		$not_immediate = Event::create( 'test_not_immediate', 2, 'sig3', array(), null, null );
		self::assertFalse( $not_immediate->is_immediate() );

		// Test that create_new() does not create an immediate event
		$new_event = Event::create_new();
		self::assertFalse( $new_event->is_immediate() );
	}

	public function testIsPausedWithPausedHooks(): void {
		$hook = 'test_paused_hook_' . uniqid();
		$event = Event::create( $hook, time() + 3600, 'sig1', array(), null, null );

		// Initially not paused
		self::assertFalse( $event->is_paused() );

		// Pause the hook
		Crontrol\Event\pause( $hook );

		// Now it should be paused
		self::assertTrue( $event->is_paused() );

		// Create another event with the same hook - should also be paused
		$another_event = Event::create( $hook, time() + 7200, 'sig2', array(), 'hourly', 3600 );
		self::assertTrue( $another_event->is_paused() );

		// Create event with different hook - should not be paused
		$different_hook = 'test_not_paused_hook_' . uniqid();
		$different_event = Event::create( $different_hook, time() + 3600, 'sig3', array(), null, null );
		self::assertFalse( $different_event->is_paused() );

		// Resume the original hook
		Crontrol\Event\resume( $hook );

		// Should no longer be paused
		self::assertFalse( $event->is_paused() );
		self::assertFalse( $another_event->is_paused() );
	}

	public function testFindsExistingEventByHookTimestampAndSig(): void {
		$hook = 'test_find_event_hook_' . uniqid();
		$timestamp = time() + 3600;
		$args = array( 'value', 42 );

		// Schedule the event
		wp_schedule_single_event( $timestamp, $hook, $args );

		// Get the signature from the scheduled event
		$all_events = Crontrol\Event\get();
		$scheduled_event = null;
		foreach ( $all_events as $event ) {
			if ( $event->hook === $hook && $event->timestamp === $timestamp ) {
				$scheduled_event = $event;
				break;
			}
		}

		self::assertInstanceOf( StandardEvent::class, $scheduled_event );

		// Find the event using the find() function
		$found_event = Crontrol\Event\find( $hook, $timestamp, $scheduled_event->sig );

		self::assertInstanceOf( StandardEvent::class, $found_event );
		self::assertSame( $hook, $found_event->hook );
		self::assertSame( $timestamp, $found_event->timestamp );
		self::assertSame( $scheduled_event->sig, $found_event->sig );
		self::assertSame( $args, $found_event->args );
	}

	public function testFindReturnsNullWithWrongTimestamp(): void {
		$hook = 'test_wrong_timestamp_hook_' . uniqid();
		$timestamp = time() + 3600;
		$args = array( 'data' );

		// Schedule the event
		wp_schedule_single_event( $timestamp, $hook, $args );

		// Get the signature
		$all_events = Crontrol\Event\get();
		$scheduled_event = null;
		foreach ( $all_events as $event ) {
			if ( $event->hook === $hook && $event->timestamp === $timestamp ) {
				$scheduled_event = $event;
				break;
			}
		}

		self::assertInstanceOf( StandardEvent::class, $scheduled_event );

		// Try to find with wrong timestamp
		$wrong_timestamp = $timestamp + 1000;
		$found_event = Crontrol\Event\find( $hook, $wrong_timestamp, $scheduled_event->sig );

		self::assertNull( $found_event, 'Should return null with wrong timestamp' );
	}

	public function testFindReturnsNullWithWrongSig(): void {
		$hook = 'test_wrong_sig_hook_' . uniqid();
		$timestamp = time() + 3600;
		$args = array( 'data' );

		// Schedule the event
		wp_schedule_single_event( $timestamp, $hook, $args );

		// Try to find with wrong signature
		$wrong_sig = 'wrong_signature_12345';
		$found_event = Crontrol\Event\find( $hook, $timestamp, $wrong_sig );

		self::assertNull( $found_event, 'Should return null with wrong signature' );
	}

	public function testFindReturnsNullWithWrongHook(): void {
		$hook = 'test_wrong_hook_original_' . uniqid();
		$timestamp = time() + 3600;
		$args = array( 'data' );

		// Schedule the event
		wp_schedule_single_event( $timestamp, $hook, $args );

		// Get the signature
		$all_events = Crontrol\Event\get();
		$scheduled_event = null;
		foreach ( $all_events as $event ) {
			if ( $event->hook === $hook && $event->timestamp === $timestamp ) {
				$scheduled_event = $event;
				break;
			}
		}

		self::assertInstanceOf( StandardEvent::class, $scheduled_event );

		// Try to find with wrong hook name
		$wrong_hook = 'wrong_hook_name_' . uniqid();
		$found_event = Crontrol\Event\find( $wrong_hook, $timestamp, $scheduled_event->sig );

		self::assertNull( $found_event, 'Should return null with wrong hook name' );
	}

	public function testFindsRecurringEvent(): void {
		$hook = 'test_find_recurring_hook_' . uniqid();
		$timestamp = time() + 3600;
		$schedule = 'hourly';

		// Schedule a recurring event
		wp_schedule_event( $timestamp, $schedule, $hook );

		// Get the signature from the scheduled event
		$all_events = Crontrol\Event\get();
		$scheduled_event = null;
		foreach ( $all_events as $event ) {
			if ( $event->hook === $hook && $event->timestamp === $timestamp ) {
				$scheduled_event = $event;
				break;
			}
		}

		self::assertInstanceOf( StandardEvent::class, $scheduled_event );
		self::assertTrue( $scheduled_event->is_recurring(), 'Event should be recurring' );

		// Find the event
		$found_event = Crontrol\Event\find( $hook, $timestamp, $scheduled_event->sig );

		self::assertInstanceOf( StandardEvent::class, $found_event );
		self::assertSame( $hook, $found_event->hook );
		self::assertSame( $timestamp, $found_event->timestamp );
		self::assertSame( $schedule, $found_event->schedule );
		self::assertTrue( $found_event->is_recurring(), 'Found event should be recurring' );
	}

	public function testFindsEventWithEmptyArgs(): void {
		$hook = 'test_find_empty_args_hook_' . uniqid();
		$timestamp = time() + 3600;

		// Schedule event with no args
		wp_schedule_single_event( $timestamp, $hook );

		// Get the signature
		$all_events = Crontrol\Event\get();
		$scheduled_event = null;
		foreach ( $all_events as $event ) {
			if ( $event->hook === $hook && $event->timestamp === $timestamp ) {
				$scheduled_event = $event;
				break;
			}
		}

		self::assertInstanceOf( StandardEvent::class, $scheduled_event );

		// Find the event
		$found_event = Crontrol\Event\find( $hook, $timestamp, $scheduled_event->sig );

		self::assertInstanceOf( StandardEvent::class, $found_event );
		self::assertSame( array(), $found_event->args );
	}
}
