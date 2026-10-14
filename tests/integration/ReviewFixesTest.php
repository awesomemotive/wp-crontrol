<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\Log;

/**
 * Regression tests for the fixes from the 1.23 security review.
 */
final class ReviewFixesTest extends Test {
	#[\Override]
	public function tear_down() {
		$_GET = array();
		wp_dequeue_style( 'wp-crontrol' );
		wp_dequeue_script( 'wp-crontrol' );
		remove_all_filters( 'wp_doing_cron' );
		remove_all_filters( 'pre_get_ready_cron_jobs' );
		delete_option( 'crontrol_log' );
		delete_option( 'uninstall_plugins' );
		delete_option( \Crontrol\RETIRED_PRO_NOTICE );
		Log::get_instance()->unobserve( 'crontrol_test_alternate' );

		parent::tear_down();
	}

	/**
	 * WP Crontrol's CSS changes list tables, so it must not load on other admin screens.
	 */
	public function testAssetsAreNotEnqueuedOnOtherScreens(): void {
		$_GET = array();
		set_current_screen( 'edit' );

		\Crontrol\enqueue_assets( 'edit.php' );

		self::assertFalse( wp_style_is( 'wp-crontrol', 'enqueued' ) );
		self::assertFalse( wp_script_is( 'wp-crontrol', 'enqueued' ) );
	}

	public function testAssetsAreEnqueuedOnTheEventsScreen(): void {
		$_GET = array( 'page' => 'wp-crontrol' );
		set_current_screen( 'tools_page_wp-crontrol' );

		\Crontrol\enqueue_assets( 'tools_page_wp-crontrol' );

		self::assertTrue( wp_style_is( 'wp-crontrol', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'wp-crontrol', 'enqueued' ) );
	}

	/**
	 * With ALTERNATE_WP_CRON, wp-cron.php runs later in a front-end request; logging starts then.
	 */
	public function testLoggingStartsWhenAlternateCronRuns(): void {
		update_option( 'crontrol_log', array( 'crontrol_test_alternate' ), true );

		self::assertFalse( has_action( 'crontrol_test_alternate' ) );

		// WordPress asks for ready jobs before it decides to run cron: nothing starts yet.
		\Crontrol\Pro\start_alternate_cron_logging( null );
		self::assertFalse( has_action( 'crontrol_test_alternate' ) );

		add_filter( 'wp_doing_cron', '__return_true' );
		add_filter( 'pre_get_ready_cron_jobs', 'Crontrol\Pro\start_alternate_cron_logging' );

		self::assertNull( \Crontrol\Pro\start_alternate_cron_logging( null ) );
		self::assertNotFalse( has_action( 'crontrol_test_alternate' ) );
		self::assertFalse( has_filter( 'pre_get_ready_cron_jobs', 'Crontrol\Pro\start_alternate_cron_logging' ) );
	}

	public function testLoggingSettingIsReadFromAutoloadedOptionsOnly(): void {
		self::assertSame( array(), Log::get_enabled_hooks() );

		update_option( 'crontrol_log', array( 'some_hook' ), true );
		self::assertSame( array( 'some_hook' ), Log::get_enabled_hooks() );
	}

	public function testRetiredProUninstallHookIsForgotten(): void {
		update_option(
			'uninstall_plugins',
			array(
				\Crontrol\RETIRED_PRO_PLUGIN => array( 'Freemius', '_uninstall_plugin_hook' ),
				'other/other.php'            => 'other_uninstall',
			)
		);

		\Crontrol\forget_retired_pro_uninstall_hook();

		self::assertSame( array( 'other/other.php' => 'other_uninstall' ), get_option( 'uninstall_plugins' ) );
	}

	public function testRetiredProNoticeShowsOnce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $user_id );

		// On a network, only super admins can manage plugins by default.
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );
		update_option( \Crontrol\RETIRED_PRO_NOTICE, 1 );

		ob_start();
		\Crontrol\show_retired_pro_notice();
		$first = (string) ob_get_clean();

		ob_start();
		\Crontrol\show_retired_pro_notice();
		$second = (string) ob_get_clean();

		self::assertStringContainsString( 'WP Crontrol Pro has been deactivated', $first );
		self::assertSame( '', $second );
	}

	public function testRetiredProIsNotActiveByDefault(): void {
		self::assertFalse( \Crontrol\retired_pro_is_active() );
	}

	/**
	 * A network-wide WP Crontrol Pro stays while WP Crontrol is only active on one site.
	 *
	 * @group ms-required
	 */
	public function testNetworkProIsKeptWhenWPCrontrolIsSiteActive(): void {
		update_site_option( 'active_sitewide_plugins', array( \Crontrol\RETIRED_PRO_PLUGIN => time() ) );

		\Crontrol\retire_pro( 'wp-crontrol/wp-crontrol.php' );

		self::assertArrayHasKey( \Crontrol\RETIRED_PRO_PLUGIN, get_site_option( 'active_sitewide_plugins' ) );
		self::assertNotFalse( has_action( 'admin_notices', 'Crontrol\show_network_pro_notice' ) );

		remove_action( 'admin_notices', 'Crontrol\show_network_pro_notice' );
		delete_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * @group ms-required
	 */
	public function testNetworkProIsRetiredByNetworkWPCrontrol(): void {
		update_site_option(
			'active_sitewide_plugins',
			array(
				\Crontrol\RETIRED_PRO_PLUGIN => time(),
				'wp-crontrol/wp-crontrol.php'  => time(),
			)
		);

		\Crontrol\retire_pro( 'wp-crontrol/wp-crontrol.php' );

		self::assertSame( array( 'wp-crontrol/wp-crontrol.php' ), array_keys( get_site_option( 'active_sitewide_plugins' ) ) );
		self::assertSame( '1', (string) get_site_option( \Crontrol\RETIRED_PRO_NOTICE ) );

		delete_site_option( 'active_sitewide_plugins' );
		delete_site_option( \Crontrol\RETIRED_PRO_NOTICE );
	}
}
