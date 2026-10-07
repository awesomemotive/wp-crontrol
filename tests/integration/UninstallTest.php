<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\EventMetricModel;
use Crontrol\Pro\EventRunModel;

/**
 * @group ms-excluded
 */
final class UninstallTest extends Test {
	#[\Override]
	public function tear_down() {
		// Put the tables back for the rest of the suite.
		\Crontrol\Pro\init_db();

		parent::tear_down();
	}

	public function testUninstallRemovesLoggingData(): void {
		global $wpdb;

		// Table creation and removal must hit real tables, not the test suite's temporary ones.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		self::assertTrue( EventRunModel::table_exists() );
		self::assertTrue( EventMetricModel::table_exists() );

		update_option( 'crontrol_log', array( 'some_hook' ) );
		update_option( 'crontrol_log_all_hooks', array( 'some_hook' ), false );
		wp_schedule_event( time() + 60, 'daily', 'crontrol/maintenance' );
		$user_id = self::factory()->user->create();
		self::assertIsInt( $user_id );
		update_user_meta( $user_id, 'crontrol_dismissed_promos', array( 'welcome' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-crontrol/wp-crontrol.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertFalse( EventRunModel::table_exists() );
		self::assertFalse( EventMetricModel::table_exists() );
		self::assertFalse( EventRunModel::is_ready() );
		self::assertFalse( get_option( 'crontrol_log', false ) );
		self::assertFalse( get_option( 'crontrol_log_all_hooks', false ) );
		self::assertFalse( wp_next_scheduled( 'crontrol/maintenance' ) );
		self::assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'crontrol_dismissed_promos'" ) );
	}
}
