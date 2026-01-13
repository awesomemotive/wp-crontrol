<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol;
use Crontrol\Event\Table;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;

class EventFilterTest extends Test {
	public function testGetFilteredEventsFiltersAllEvents(): void {
		$timestamp = time() + 3600;
		$hook = 'test_filter_all_events_hook';
		wp_schedule_single_event( $timestamp, $hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include our test event and any existing core events
		self::assertGreaterThan( 0, count( $filtered['all'] ) );

		// Verify our test event is included
		$hook_names = array_column( $filtered['all'], 'hook' );
		self::assertContains( $hook, $hook_names, 'Test event not found in "all" filter' );
	}

	public function testGetFilteredEventsFiltersCustomEvents(): void {
		// Schedule a custom (non-core) event
		$timestamp = time() + 3600;
		$hook = 'test_custom_filter_event_hook';
		wp_schedule_single_event( $timestamp, $hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include our custom event
		$hook_names = array_column( $filtered['custom'], 'hook' );
		self::assertContains( $hook, $hook_names, 'Custom event not found in "custom" filter' );

		// Should not include core events like wp_version_check in custom filter
		self::assertNotContains( 'wp_version_check', $hook_names, 'Core event should not be in "custom" filter' );
	}

	public function testGetFilteredEventsFiltersCoreEvents(): void {
		// Schedule a core event (if not already scheduled)
		if ( ! wp_next_scheduled( 'wp_version_check' ) ) {
			wp_schedule_event( time() + 3600, 'twicedaily', 'wp_version_check' );
		}

		// Schedule a custom event
		$timestamp = time() + 7200;
		$custom_hook = 'test_custom_not_in_core_filter';
		wp_schedule_single_event( $timestamp, $custom_hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include core events
		$hook_names = array_column( $filtered['core'], 'hook' );
		self::assertContains( 'wp_version_check', $hook_names, 'Core event wp_version_check not found in "core" filter' );

		// Should not include custom events
		self::assertNotContains( $custom_hook, $hook_names, 'Custom event should not be in "core" filter' );
	}

	public function testGetFilteredEventsFiltersPhpEvents(): void {
		// Schedule a PHP cron job
		$timestamp = time() + 3600;
		$hook = PHPCronEvent::HOOK_NAME;
		$args = array(
			array(
				'code' => 'echo "test";',
				'name' => 'Test PHP Job for Filter',
				'hash' => wp_hash( 'echo "test";' ),
			),
		);
		wp_schedule_single_event( $timestamp, $hook, $args );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should only include PHP cron jobs
		self::assertContainsOnlyInstancesOf( PHPCronEvent::class, $filtered['php'] );

		// Should include our test PHP job
		self::assertGreaterThan( 0, count( $filtered['php'] ), 'No PHP events found in filter' );
	}

	public function testGetFilteredEventsFiltersNoActionEvents(): void {
		// Create an event with no registered callbacks
		$timestamp = time() + 3600;
		$hook = 'test_event_with_no_callbacks_filter_' . uniqid();
		wp_schedule_single_event( $timestamp, $hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include our event with no callbacks
		$hook_names = array_column( $filtered['noaction'], 'hook' );
		self::assertContains( $hook, $hook_names, 'Event with no callbacks not found in "noaction" filter' );

		// All events in this filter should have no actions
		foreach ( $filtered['noaction'] as $event ) {
			$hook_callbacks = $event->get_callbacks();
			self::assertEmpty( $hook_callbacks );
		}
	}

	public function testGetFilteredEventsFiltersUrlEvents(): void {
		// Schedule a URL cron job
		$timestamp = time() + 3600;
		$hook = URLCronEvent::HOOK_NAME;
		$url = 'https://example.com/webhook';
		$args = array(
			array(
				'url' => $url,
				'name' => 'Test URL Job for Filter',
				'hash' => wp_hash( $url ),
			),
		);
		wp_schedule_single_event( $timestamp, $hook, $args );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should only include URL cron jobs
		self::assertContainsOnlyInstancesOf( URLCronEvent::class, $filtered['url'] );

		// Should include our test URL job
		self::assertGreaterThan( 0, count( $filtered['url'] ), 'No URL events found in filter' );
	}

	public function testGetFilteredEventsFiltersPausedEvents(): void {
		// Schedule an event and then pause it
		$timestamp = time() + 3600;
		$hook = 'test_paused_filter_event_hook';
		wp_schedule_single_event( $timestamp, $hook );

		// Pause the event
		Crontrol\Event\pause( $hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include our paused event
		$hook_names = array_column( $filtered['paused'], 'hook' );
		self::assertContains( $hook, $hook_names, 'Paused event not found in "paused" filter' );

		// All events in this filter should be paused
		foreach ( $filtered['paused'] as $event ) {
			self::assertTrue( $event->is_paused() );
		}
	}

	public function testGetFilteredEventsFiltersDuplicatedEvents(): void {
		// Schedule two events with the same hook name (duplicates)
		$timestamp1 = time() + 3600;
		$timestamp2 = time() + 7200;
		$duplicate_hook = 'test_duplicate_filter_hook';

		wp_schedule_single_event( $timestamp1, $duplicate_hook );
		wp_schedule_single_event( $timestamp2, $duplicate_hook );

		// Schedule a unique event (should not appear in duplicated filter)
		$unique_timestamp = time() + 5400;
		$unique_hook = 'test_unique_filter_hook_' . uniqid();
		wp_schedule_single_event( $unique_timestamp, $unique_hook );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Should include our duplicated events
		$hook_names = array_column( $filtered['duplicated'], 'hook' );
		self::assertContains( $duplicate_hook, $hook_names, 'Duplicated event not found in "duplicated" filter' );

		// Should not include unique events
		self::assertNotContains( $unique_hook, $hook_names, 'Unique event should not be in "duplicated" filter' );

		// Count how many instances of the duplicate hook are in the filter
		$duplicate_count = array_count_values( $hook_names )[$duplicate_hook] ?? 0;
		self::assertEquals( 2, $duplicate_count, 'Expected exactly 2 instances of the duplicate hook in the filter' );

		// All events in this filter should have hooks that appear more than once
		$hook_counts = Crontrol\Event\count_by_hook();
		foreach ( $filtered['duplicated'] as $event ) {
			self::assertGreaterThan( 1, $hook_counts[ $event->hook ] ?? 0, "Event with hook '{$event->hook}' should appear more than once" );
		}
	}

	public function testGetFilteredEventsWithNoDuplicates(): void {
		// Clear all scheduled events to ensure no duplicates
		_set_cron_array( array() );

		// Schedule only unique events
		$timestamp1 = time() + 3600;
		$timestamp2 = time() + 7200;
		$timestamp3 = time() + 10800;

		wp_schedule_single_event( $timestamp1, 'test_unique_hook_1_' . uniqid() );
		wp_schedule_single_event( $timestamp2, 'test_unique_hook_2_' . uniqid() );
		wp_schedule_single_event( $timestamp3, 'test_unique_hook_3_' . uniqid() );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// The duplicated filter should be empty when no duplicates exist
		self::assertEmpty( $filtered['duplicated'], 'Duplicated filter should be empty when no duplicate hooks exist' );
	}

	public function testGetFilteredEventsWithMultipleDuplicatedHooks(): void {
		// Schedule duplicates for multiple different hooks
		$timestamp_base = time();

		// First duplicate pair
		$hook1 = 'test_duplicate_hook_a';
		wp_schedule_single_event( $timestamp_base + 3600, $hook1 );
		wp_schedule_single_event( $timestamp_base + 7200, $hook1 );

		// Second duplicate pair
		$hook2 = 'test_duplicate_hook_b';
		wp_schedule_single_event( $timestamp_base + 10800, $hook2 );
		wp_schedule_single_event( $timestamp_base + 14400, $hook2 );

		// Third hook with three instances
		$hook3 = 'test_triplicate_hook_c';
		wp_schedule_single_event( $timestamp_base + 18000, $hook3 );
		wp_schedule_single_event( $timestamp_base + 21600, $hook3 );
		wp_schedule_single_event( $timestamp_base + 25200, $hook3 );

		$all_events = Crontrol\Event\get();
		$filtered = Table::get_filtered_events( $all_events );

		// Count instances of each hook in the duplicated filter
		$hook_names = array_column( $filtered['duplicated'], 'hook' );
		$hook_counts_in_filter = array_count_values( $hook_names );

		// Should have 2 instances of hook1
		self::assertEquals( 2, $hook_counts_in_filter[$hook1] ?? 0, 'Expected 2 instances of first duplicate hook' );

		// Should have 2 instances of hook2
		self::assertEquals( 2, $hook_counts_in_filter[$hook2] ?? 0, 'Expected 2 instances of second duplicate hook' );

		// Should have 3 instances of hook3
		self::assertEquals( 3, $hook_counts_in_filter[$hook3] ?? 0, 'Expected 3 instances of triplicate hook' );

		// Total should be 7 events (2 + 2 + 3)
		self::assertCount( 7, $filtered['duplicated'], 'Expected 7 total duplicated events' );
	}
}
