<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\EventMetricModel;
use Crontrol\Pro\EventRunModel;

/**
 * Deleting WP Crontrol while the retired WP Crontrol Pro is still active network-wide keeps the
 * log tables and settings that Pro shares.
 *
 * @group ms-required
 */
final class UninstallWithProTest extends Test {
	private string $pro_dir;

	#[\Override]
	public function set_up() {
		parent::set_up();

		$this->pro_dir = WP_PLUGIN_DIR . '/wp-crontrol-pro';

		if ( ! is_dir( $this->pro_dir ) ) {
			mkdir( $this->pro_dir );
		}

		file_put_contents( $this->pro_dir . '/wp-crontrol-pro.php', "<?php\n/* Plugin Name: WP Crontrol Pro (test stub) */\n" );
	}

	#[\Override]
	public function tear_down() {
		unlink( $this->pro_dir . '/wp-crontrol-pro.php' );
		rmdir( $this->pro_dir );
		delete_site_option( 'active_sitewide_plugins' );

		parent::tear_down();
	}

	public function testSharedDataIsKeptWhileNetworkProIsActive(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		update_site_option( 'active_sitewide_plugins', array( 'wp-crontrol-pro/wp-crontrol-pro.php' => time() ) );
		update_option( 'crontrol_log', array( 'some_hook' ), true );
		$user_id = self::factory()->user->create();
		self::assertIsInt( $user_id );
		update_user_meta( $user_id, 'crontrol_dismissed_promos', array( 'welcome' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-crontrol/wp-crontrol.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertTrue( EventRunModel::table_exists() );
		self::assertTrue( EventMetricModel::table_exists() );
		self::assertSame( array( 'some_hook' ), get_option( 'crontrol_log' ) );
		// WP Crontrol's own user settings still go.
		self::assertSame( '', get_user_meta( $user_id, 'crontrol_dismissed_promos', true ) );
	}
}
