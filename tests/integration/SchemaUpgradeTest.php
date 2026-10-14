<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\EventRunModel;

/**
 * A schema change that fails must not be recorded as done.
 *
 * @group ms-excluded
 */
final class SchemaUpgradeTest extends Test {
	#[\Override]
	public function set_up() {
		parent::set_up();

		// Work on the real table, not a temporary copy, so SHOW TABLES and ALTER behave as on a site.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	#[\Override]
	public function tear_down() {
		remove_all_filters( 'dbdelta_queries' );
		delete_site_transient( 'crontrol_event_logs_retry' );
		delete_site_option( 'crontrol_event_logs_version' );
		EventRunModel::init_db();

		parent::tear_down();
	}

	public function testAFailedAlterIsRetriedNotRecorded(): void {
		global $wpdb;

		EventRunModel::init_db();
		self::assertTrue( EventRunModel::columns_exist() );

		// Make the table look like an older version that lacks a column, and make the upgrade fail.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', EventRunModel::get_table(), 'mail_failed' ) );
		update_site_option( 'crontrol_event_logs_version', 11 );
		delete_site_transient( 'crontrol_event_logs_retry' );
		add_filter( 'dbdelta_queries', '__return_empty_array' );

		self::assertFalse( EventRunModel::init_db() );
		self::assertSame( 11, (int) get_site_option( 'crontrol_event_logs_version' ) );
		self::assertFalse( EventRunModel::is_ready() );

		// Once the upgrade can run, it completes.
		remove_filter( 'dbdelta_queries', '__return_empty_array' );
		delete_site_transient( 'crontrol_event_logs_retry' );

		self::assertTrue( EventRunModel::init_db() );
		self::assertTrue( EventRunModel::columns_exist() );
		self::assertTrue( EventRunModel::is_ready() );
	}
}
