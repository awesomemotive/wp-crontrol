<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\QueryBuilder;
use DateTimeImmutable;
use DateTimeZone;

final class LogQueryBuilderTest extends Test {
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Create test data
		$timezone = new DateTimeZone( 'UTC' );
		$base_time = new DateTimeImmutable( '2025-01-01 12:00:00', $timezone );

		$hooks = [ 'test_hook_a', 'test_hook_b', 'test_hook_c' ];
		$statuses = [ EventRun::STATUS_COMPLETED, EventRun::STATUS_ERRORED, EventRun::STATUS_RUNNING ];

		// Create 15 test events with varied data
		for ( $i = 0; $i < 15; $i++ ) {
			$event = new EventRun();
			$event->site_id = get_current_blog_id();
			$event->hook = $hooks[ $i % 3 ];
			$event->actions = [ 'test_action_' . $i ];
			$event->schedule = $i % 2 === 0 ? 'hourly' : 'daily';
			$event->args = [ 'test' => $i ];
			$event->status = $statuses[ $i % 3 ];
			$event->started_at = $base_time->modify( '+' . $i . ' minutes' );
			$event->scheduled_at = $base_time->modify( '+' . ( $i - 5 ) . ' minutes' );
			$event->duration = 100 * ( $i + 1 );
			$event->fatal = $i % 5 === 0;
			$event->warning = $i % 3 === 0;
			$event->https = $i * 2;

			EventRunModel::create( $event );
		}
	}

	public static function tearDownAfterClass(): void {
		// Clean up test data by truncating the table
		EventRunModel::truncate();
		parent::tearDownAfterClass();
	}

	public function testGetReturnsTypedItems(): void {
		$query = new QueryBuilder( EventRunModel::class );
		$results = $query->per_page( 5 )->get();

		$this->assertIsArray( $results );
		$this->assertCount( 5, $results );
		$this->assertContainsOnlyInstancesOf( EventRun::class, $results );

		// Verify properties are properly typed
		$first = $results[0];
		$this->assertIsInt( $first->ID );
		$this->assertIsString( $first->hook );
		$this->assertIsArray( $first->args );
		$this->assertInstanceOf( DateTimeImmutable::class, $first->started_at );
		$this->assertIsBool( $first->fatal );
	}

	public function testGetRawReturnsArrays(): void {
		$query = new QueryBuilder( EventRunModel::class );
		$results = $query->per_page( 5 )->get_raw();

		$this->assertIsArray( $results );
		$this->assertCount( 5, $results );

		// Verify each result is an array with expected keys
		$first = $results[0];
		$this->assertIsArray( $first );
		$this->assertArrayHasKey( 'ID', $first );
		$this->assertArrayHasKey( 'hook', $first );
		$this->assertArrayHasKey( 'status', $first );
		$this->assertArrayHasKey( 'duration', $first );

		// Verify basic type casting is applied
		$this->assertIsInt( $first['ID'] );
		$this->assertIsString( $first['hook'] );
		$this->assertIsInt( $first['duration'] );
	}

	public function testBeforeDirectionPagination(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Get the latest 5 items
		$page1 = $query->per_page( 5 )->before( 0 )->get();
		$this->assertCount( 5, $page1 );

		// Verify descending order (newest first)
		$this->assertGreaterThan( $page1[1]->ID, $page1[0]->ID );

		// Get next page using cursor
		$last_id = $page1[ count( $page1 ) - 1 ]->ID;
		$page2 = $query->per_page( 5 )->before( $last_id )->get();
		$this->assertCount( 5, $page2 );

		// Verify no overlap between pages
		$page1_ids = wp_list_pluck( $page1, 'ID' );
		$page2_ids = wp_list_pluck( $page2, 'ID' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );

		// Verify page2 items are older than page1
		$this->assertLessThan( $page1[ count( $page1 ) - 1 ]->ID, $page2[0]->ID );
	}

	public function testAfterDirectionPagination(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Get all items to find the oldest one
		$all_items = $query->per_page( 100 )->before( 0 )->get();
		$oldest_id = $all_items[ count( $all_items ) - 1 ]->ID;

		// Get 5 items after the oldest (going forward in time)
		$page1 = $query->per_page( 5 )->after( $oldest_id )->get();
		$this->assertCount( 5, $page1 );

		// Verify descending order is maintained after reversal
		$this->assertGreaterThan( $page1[1]->ID, $page1[0]->ID );

		// Get next page
		$first_id = $page1[0]->ID;
		$page2 = $query->per_page( 5 )->after( $first_id )->get();

		// Verify no overlap
		$page1_ids = wp_list_pluck( $page1, 'ID' );
		$page2_ids = wp_list_pluck( $page2, 'ID' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	public function testWhereClauseFiltering(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Filter by hook
		$results = $query->where( 'hook', QueryBuilder::OPERATOR_EQUALS, 'test_hook_a' )->per_page( 20 )->get();
		$this->assertGreaterThan( 0, count( $results ) );
		$this->assertNotEmpty( $results, 'Results array should not be empty' );
		foreach ( $results as $item ) {
			$this->assertEquals( 'test_hook_a', $item->hook );
		}

		// Filter by status
		$query2 = new QueryBuilder( EventRunModel::class );
		$errored = $query2->where( 'status', QueryBuilder::OPERATOR_EQUALS, EventRun::STATUS_ERRORED )->per_page( 20 )->get();
		$this->assertGreaterThan( 0, count( $errored ) );
		$this->assertNotEmpty( $errored, 'Errored results array should not be empty' );
		foreach ( $errored as $item ) {
			$this->assertEquals( EventRun::STATUS_ERRORED, $item->status );
		}

		// Filter by duration greater than
		$query3 = new QueryBuilder( EventRunModel::class );
		$long_running = $query3->where( 'duration', QueryBuilder::OPERATOR_GREATER_THAN, 1000 )->per_page( 20 )->get();
		$this->assertGreaterThan( 0, count( $long_running ) );
		$this->assertNotEmpty( $long_running, 'Long running results array should not be empty' );
		foreach ( $long_running as $item ) {
			$this->assertGreaterThan( 1000, $item->duration );
		}
	}

	public function testMultipleWhereConditions(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Multiple conditions - test_hook_b always has STATUS_ERRORED in our test data
		$results = $query
			->where( 'hook', QueryBuilder::OPERATOR_EQUALS, 'test_hook_b' )
			->where( 'status', QueryBuilder::OPERATOR_EQUALS, EventRun::STATUS_ERRORED )
			->per_page( 20 )
			->get();

		$this->assertNotEmpty( $results, 'Results array with multiple conditions should not be empty' );
		foreach ( $results as $item ) {
			$this->assertEquals( 'test_hook_b', $item->hook );
			$this->assertEquals( EventRun::STATUS_ERRORED, $item->status );
		}
	}

	public function testGetRawWithPagination(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Test get_raw with before pagination
		$page1 = $query->per_page( 3 )->before( 0 )->get_raw();
		$this->assertCount( 3, $page1 );
		$this->assertIsArray( $page1[0] );
		$this->assertArrayHasKey( 'ID', $page1[0] );

		// Get next page
		$last_id = $page1[ count( $page1 ) - 1 ]['ID'];
		$page2 = $query->per_page( 3 )->before( $last_id )->get_raw();
		$this->assertCount( 3, $page2 );

		// Verify no overlap
		$page1_ids = array_column( $page1, 'ID' );
		$page2_ids = array_column( $page2, 'ID' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	public function testGetRawWithAfterDirection(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Get all to find oldest
		$all = $query->per_page( 100 )->before( 0 )->get_raw();
		$oldest_id = $all[ count( $all ) - 1 ]['ID'];

		// Test after direction with get_raw
		$results = $query->per_page( 4 )->after( $oldest_id )->get_raw();
		$this->assertCount( 4, $results );

		// Verify descending order is maintained
		$this->assertGreaterThan( $results[1]['ID'], $results[0]['ID'] );
	}

	public function testEmptyResultSet(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Query for non-existent hook
		$results = $query->where( 'hook', QueryBuilder::OPERATOR_EQUALS, 'non_existent_hook' )->get();
		$this->assertIsArray( $results );
		$this->assertEmpty( $results );

		// Same for get_raw
		$query2 = new QueryBuilder( EventRunModel::class );
		$raw_results = $query2->where( 'hook', QueryBuilder::OPERATOR_EQUALS, 'non_existent_hook' )->get_raw();
		$this->assertIsArray( $raw_results );
		$this->assertEmpty( $raw_results );
	}

	public function testPerPageLimit(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Test various per_page values
		$test_cases = [ 1, 3, 7, 10 ];

		foreach ( $test_cases as $limit ) {
			$query = new QueryBuilder( EventRunModel::class );
			$results = $query->per_page( $limit )->get();
			$this->assertCount( $limit, $results );
		}
	}

	public function testCountReturnsTotalRows(): void {
		$query = new QueryBuilder( EventRunModel::class );
		$this->assertSame( 15, $query->count() );
	}

	public function testCountAppliesWhereFilters(): void {
		$query = new QueryBuilder( EventRunModel::class );
		$count = $query->where( 'hook', QueryBuilder::OPERATOR_EQUALS, 'test_hook_a' )->count();

		// 15 rows, every third (i=0,3,6,9,12) assigned to test_hook_a.
		$this->assertSame( 5, $count );
	}

	public function testCountWithStartIncludesStartRow(): void {
		// Fetch all rows in descending order so index 0 is the highest ID.
		$all = ( new QueryBuilder( EventRunModel::class ) )->per_page( 100 )->before( 0 )->get();
		$top_id = $all[0]->ID;

		$query = new QueryBuilder( EventRunModel::class );
		$count = $query->count( $top_id );

		// The snapshot starting at the top row must include that row itself.
		// This guards the off-by-one that caused "50,000 items" to display as
		// "49,999" on page 2 of the keyset-paginated logs table.
		$this->assertSame( count( $all ), $count );
	}

	public function testCountWithStartExcludesNewerRows(): void {
		$all = ( new QueryBuilder( EventRunModel::class ) )->per_page( 100 )->before( 0 )->get();
		$second_id = $all[1]->ID;

		$query = new QueryBuilder( EventRunModel::class );

		// Starting the snapshot at the second-highest ID should drop exactly
		// the top row (which was added "after" the snapshot).
		$this->assertSame( count( $all ) - 1, $query->count( $second_id ) );
	}

	public function testBooleanFieldHandling(): void {
		$query = new QueryBuilder( EventRunModel::class );

		// Filter by boolean field
		$fatal_events = $query->where( 'fatal', QueryBuilder::OPERATOR_EQUALS, 1 )->per_page( 20 )->get();
		$this->assertNotEmpty( $fatal_events, 'Fatal events array should not be empty' );
		foreach ( $fatal_events as $event ) {
			$this->assertTrue( $event->fatal );
		}

		// Test with get_raw
		$query2 = new QueryBuilder( EventRunModel::class );
		$warning_events = $query2->where( 'warning', QueryBuilder::OPERATOR_EQUALS, 1 )->per_page( 20 )->get_raw();
		$this->assertNotEmpty( $warning_events, 'Warning events array should not be empty' );
		foreach ( $warning_events as $event ) {
			$this->assertEquals( 1, $event['warning'] );
		}
	}
}
