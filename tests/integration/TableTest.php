<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\Table;
use Crontrol\Event\URLCronEvent;
use Crontrol\Tests\Context\AllCronsDisabledFeatureContext;
use Crontrol\Tests\Context\CannotEditFilesUserContext;
use Crontrol\Tests\Context\NoPermissionsUserContext;
use Crontrol\Tests\Context\PHPCronsDisabledFeatureContext;
use Crontrol\Tests\Context\TestFeatureContext;
use Crontrol\Tests\Context\TestUserContext;
use Crontrol\Tests\Context\URLCronsDisabledFeatureContext;

class TableTest extends Test {
	/**
	 * Create a testable table instance with specific contexts.
	 *
	 * @param TestUserContext|null $user_context The test user context to use.
	 * @param TestFeatureContext|null $feature_context The test feature context to use.
	 * @return Table
	 */
	private function create_test_table( ?TestUserContext $user_context = null, ?TestFeatureContext $feature_context = null ): Table {
		if ( null === $user_context ) {
			$user_context = new TestUserContext();
		}
		if ( null === $feature_context ) {
			$feature_context = new TestFeatureContext();
		}
		return new Table( $user_context, $feature_context );
	}

	/**
	 * Test that PHP cron events show edit link when user can manage and they're enabled.
	 */
	public function test_php_cron_edit_link_when_allowed(): void {
		$table = $this->create_test_table();

		// Create a PHP cron event
		$code = 'echo "test";';
		$hash = wp_hash( $code );
		$args = [
			[
				'name' => 'Test PHP Cron',
				'code' => $code,
				'hash' => $hash,
			],
		];
		$event = Event::create(
			PHPCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'run', $actions );
		$this->assertArrayHasKey( 'delete', $actions );
	}

	/**
	 * Test that PHP cron events don't show edit link when user cannot manage them.
	 */
	public function test_php_cron_no_edit_link_when_user_cannot_manage(): void {
		$table = $this->create_test_table( new CannotEditFilesUserContext() );

		// Create a PHP cron event
		$code = 'echo "test";';
		$hash = wp_hash( $code );
		$args = [
			[
				'name' => 'Test PHP Cron',
				'code' => $code,
				'hash' => $hash,
			],
		];
		$event = Event::create(
			PHPCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayNotHasKey( 'edit', $actions );
		// PHP crons can still be run if they're enabled, even if user can't edit
		$this->assertArrayHasKey( 'run', $actions );
		// But cannot delete - need permission
		$this->assertArrayNotHasKey( 'delete', $actions );
	}

	/**
	 * Test that PHP cron events don't show edit link when they're disabled.
	 */
	public function test_php_cron_no_edit_link_when_disabled(): void {
		$table = $this->create_test_table( null, new PHPCronsDisabledFeatureContext() );

		// Create a PHP cron event
		$code = 'echo "test";';
		$hash = wp_hash( $code );
		$args = [
			[
				'name' => 'Test PHP Cron',
				'code' => $code,
				'hash' => $hash,
			],
		];
		$event = Event::create(
			PHPCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayNotHasKey( 'edit', $actions );
		$this->assertArrayNotHasKey( 'run', $actions );
		$this->assertArrayHasKey( 'delete', $actions ); // Can still delete if user has permission
	}

	/**
	 * Test that URL cron events show edit link when user can manage and they're enabled.
	 */
	public function test_url_cron_edit_link_when_allowed(): void {
		$table = $this->create_test_table();

		// Create a URL cron event
		$args = [
			[
				'name' => 'Test URL Cron',
				'url' => 'https://example.com/webhook',
				'hash' => wp_hash( 'https://example.com/webhook' ),
			],
		];
		$event = Event::create(
			URLCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'run', $actions );
		$this->assertArrayHasKey( 'delete', $actions );
	}

	/**
	 * Test that URL cron events don't show edit link when user cannot manage them.
	 */
	public function test_url_cron_no_edit_link_when_user_cannot_manage(): void {
		$table = $this->create_test_table( new NoPermissionsUserContext() );

		// Create a URL cron event
		$args = [
			[
				'name' => 'Test URL Cron',
				'url' => 'https://example.com/webhook',
				'hash' => wp_hash( 'https://example.com/webhook' ),
			],
		];
		$event = Event::create(
			URLCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayNotHasKey( 'edit', $actions );
		// URL crons can still be run if they're enabled, even if user can't edit
		$this->assertArrayHasKey( 'run', $actions );
		// But cannot delete - need permission
		$this->assertArrayNotHasKey( 'delete', $actions );
	}

	/**
	 * Test that URL cron events don't show edit link when they're disabled.
	 */
	public function test_url_cron_no_edit_link_when_disabled(): void {
		$table = $this->create_test_table( null, new URLCronsDisabledFeatureContext() );

		// Create a URL cron event
		$args = [
			[
				'name' => 'Test URL Cron',
				'url' => 'https://example.com/webhook',
				'hash' => wp_hash( 'https://example.com/webhook' ),
			],
		];
		$event = Event::create(
			URLCronEvent::HOOK_NAME,
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			null,
			null
		);

		$actions = $table->get_row_action_links( $event );

		$this->assertArrayNotHasKey( 'edit', $actions );
		$this->assertArrayNotHasKey( 'run', $actions );
		$this->assertArrayHasKey( 'delete', $actions ); // Can still delete if user has permission
	}

	/**
	 * Test paginating events.
	 */
	public function test_paginate_events(): void {
		// Create 10 test events
		$events = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$events[] = Event::create( "hook_{$i}", time() + 3600, '', [], null, null );
		}

		// Page 1, 3 per page should return first 3 events
		$paginated = Table::paginate_events( $events, 1, 3 );
		$this->assertCount( 3, $paginated );

		// Page 2, 3 per page should return next 3 events
		$paginated = Table::paginate_events( $events, 2, 3 );
		$this->assertCount( 3, $paginated );

		// Page 4, 3 per page should return last event
		$paginated = Table::paginate_events( $events, 4, 3 );
		$this->assertCount( 1, $paginated );

		// Page beyond range should return empty array
		$paginated = Table::paginate_events( $events, 10, 3 );
		$this->assertCount( 0, $paginated );

		// Empty events array should return empty array
		$paginated = Table::paginate_events( [], 1, 10 );
		$this->assertCount( 0, $paginated );
	}

	/**
	 * Test filtering events by search term.
	 */
	public function test_filter_events_by_search(): void {
		// Create test events
		$events = [
			Event::create( 'my_custom_hook', time() + 3600, '', [], null, null ),
			Event::create( 'another_hook', time() + 3600, '', [], null, null ),
			Event::create( 'custom_event', time() + 3600, '', [], null, null ),
		];

		// Filter by 'custom' should return 2 events
		$filtered = Table::filter_events_by_search( $events, 'custom' );
		$this->assertCount( 2, $filtered );

		// Filter by 'another' should return 1 event
		$filtered = Table::filter_events_by_search( $events, 'another' );
		$this->assertCount( 1, $filtered );

		// Filter by 'nonexistent' should return 0 events
		$filtered = Table::filter_events_by_search( $events, 'nonexistent' );
		$this->assertCount( 0, $filtered );
	}

	/**
	 * Test checking for integrity failures.
	 */
	public function test_has_integrity_failures(): void {
		// Create test events without integrity failures
		$events = [
			Event::create( 'test_hook_1', time() + 3600, '', [], null, null ),
			Event::create( 'test_hook_2', time() + 3600, '', [], null, null ),
		];

		// Should return false when no integrity failures
		$this->assertFalse( Table::has_integrity_failures( $events ) );

		// Empty array should return false
		$this->assertFalse( Table::has_integrity_failures( [] ) );
	}

	/**
	 * Test that standard cron events always show edit link regardless of PHP/URL settings.
	 */
	public function test_standard_cron_always_editable(): void {
		// Create a standard cron event
		$args = [];
		$event = Event::create(
			'my_custom_hook',
			time() + 3600,
			md5( serialize( $args ) ),
			$args,
			'hourly',
			HOUR_IN_SECONDS
		);

		$table = $this->create_test_table( null, new AllCronsDisabledFeatureContext() );

		$actions = $table->get_row_action_links( $event );

		// Standard events should be editable when user has manage_options capability,
		// even when PHP/URL cron features are disabled
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'run', $actions );
	}
}
