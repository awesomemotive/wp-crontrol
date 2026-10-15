<?php
/**
 * Retires the WP Crontrol Pro plugin, whose features are part of WP Crontrol from 2.0.0.
 *
 * The old plugin bundles its own copy of WP Crontrol and declares the same functions and classes,
 * so the two can't run together. This file has no dependencies so it can run before anything else
 * is loaded, and its names don't clash with the old plugin or its bundled copy.
 */

namespace Crontrol;

const RETIRED_PRO_PLUGIN = 'wp-crontrol-pro/wp-crontrol-pro.php';
const RETIRED_PRO_NOTICE = 'crontrol_retired_pro_notice';

/**
 * Whether the old WP Crontrol Pro plugin is loaded or active on this site or network.
 */
function retired_pro_is_active(): bool {
	if ( defined( 'Crontrol\Pro\WP_CRONTROL_PRO_VERSION' ) ) {
		return true;
	}

	$active = in_array( RETIRED_PRO_PLUGIN, (array) get_option( 'active_plugins', array() ), true )
		|| ( is_multisite() && array_key_exists( RETIRED_PRO_PLUGIN, (array) get_site_option( 'active_sitewide_plugins', array() ) ) );

	// WordPress skips an active plugin whose file is gone, so that isn't a conflict.
	return $active && file_exists( WP_PLUGIN_DIR . '/' . RETIRED_PRO_PLUGIN );
}

/**
 * Deactivates the old WP Crontrol Pro plugin and leaves a notice for the next admin screen.
 *
 * WP Crontrol doesn't load on this request. The old plugin's own copy of WP Crontrol runs instead,
 * as it did before, and WP Crontrol takes over from the next request. Logs and logging settings
 * use the same tables and options, so they carry over.
 *
 * @param string $plugin_file WP Crontrol's plugin file, relative to the plugins directory.
 */
function retire_pro( string $plugin_file ): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$network = is_multisite() && is_plugin_active_for_network( RETIRED_PRO_PLUGIN );

	// A network-wide WP Crontrol Pro is only replaced by a network-wide WP Crontrol. Otherwise the
	// other sites would lose it, so it stays, and this site keeps running its copy of WP Crontrol.
	if ( $network && ! is_plugin_active_for_network( $plugin_file ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\show_network_pro_notice' );
		return;
	}

	deactivate_plugins( RETIRED_PRO_PLUGIN, true, $network );

	if ( $network ) {
		update_site_option( RETIRED_PRO_NOTICE, 1 );
	} else {
		update_option( RETIRED_PRO_NOTICE, 1, true );
	}

	// WP Crontrol reads the logging setting from the autoloaded options only.
	if ( false !== get_option( 'crontrol_log', false ) ) {
		wp_set_option_autoload( 'crontrol_log', true );
	}

	// The old plugin kept every hook it had seen in an autoloaded option.
	if ( false !== get_option( 'crontrol_log_all_hooks', false ) ) {
		wp_set_option_autoload( 'crontrol_log_all_hooks', false );
	}

	// Its uninstall hook loads the old plugin, which would clash with WP Crontrol when it's deleted.
	// The old plugin may register the hook again later in this request, so remove it at the end too.
	forget_retired_pro_uninstall_hook();
	add_action( 'shutdown', __NAMESPACE__ . '\forget_retired_pro_uninstall_hook' );
}

/**
 * Removes the old WP Crontrol Pro plugin's uninstall hook, so deleting it just deletes its files.
 */
function forget_retired_pro_uninstall_hook(): void {
	$uninstallable = get_option( 'uninstall_plugins' );

	if ( is_array( $uninstallable ) && isset( $uninstallable[ RETIRED_PRO_PLUGIN ] ) ) {
		unset( $uninstallable[ RETIRED_PRO_PLUGIN ] );
		update_option( 'uninstall_plugins', $uninstallable );
	}
}

/**
 * Shows a one-time notice after the old WP Crontrol Pro plugin was deactivated.
 */
function show_retired_pro_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$network = is_network_admin();

	if ( ! ( $network ? get_site_option( RETIRED_PRO_NOTICE ) : get_option( RETIRED_PRO_NOTICE ) ) ) {
		return;
	}

	if ( $network ) {
		delete_site_option( RETIRED_PRO_NOTICE );
	} else {
		delete_option( RETIRED_PRO_NOTICE );
	}

	// A copy of the plugin left in place keeps its uninstall hook out of the way when it's deleted later.
	forget_retired_pro_uninstall_hook();

	printf(
		'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
		esc_html__( 'WP Crontrol Pro’s features are now included in WP Crontrol, so WP Crontrol Pro has been deactivated. You can delete it. Your logs are kept.', 'wp-crontrol' )
	);
}

/**
 * Explains why WP Crontrol isn't running on a site where WP Crontrol Pro is active network-wide.
 */
function show_network_pro_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'WP Crontrol Pro is active across the network, so this site is still using it. Its features are now included in WP Crontrol: network activate WP Crontrol to replace it on every site.', 'wp-crontrol' )
	);
}
