<?php
/**
 * Removes the data that cron event logging stores, when WP Crontrol is deleted.
 *
 * Custom cron schedules and cron events are left in place, as they always have been,
 * because other plugins and the site itself may depend on them.
 *
 * @package wp-crontrol
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'crontrol_uninstall_pro_in_use' ) ) {
	/**
	 * Whether the retired WP Crontrol Pro plugin is still active on the network or on any site.
	 *
	 * It uses the same log tables and options, so they're left in place while it's in use.
	 */
	function crontrol_uninstall_pro_in_use(): bool {
		$pro = 'wp-crontrol-pro/wp-crontrol-pro.php';

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $pro ) ) {
			return false;
		}

		if ( ! is_multisite() ) {
			return in_array( $pro, (array) get_option( 'active_plugins', array() ), true );
		}

		// Every network, not just this one: the tables are shared by all of them.
		foreach ( (array) get_networks( array(
			'fields' => 'ids',
			'number' => 0,
		) ) as $network_id ) {
			if ( array_key_exists( $pro, (array) get_network_option( (int) $network_id, 'active_sitewide_plugins', array() ) ) ) {
				return true;
			}
		}

		foreach ( get_sites( array(
			'fields' => 'ids',
			'number' => 0,
		) ) as $site_id ) {
			if ( in_array( $pro, (array) get_blog_option( (int) $site_id, 'active_plugins', array() ), true ) ) {
				return true;
			}
		}

		return false;
	}
}

// WP Crontrol's own user settings go either way.
delete_metadata( 'user', 0, 'crontrol_dismissed_promos', '', true );
delete_metadata( 'user', 0, 'crontrol_seen_tabs', '', true );

if ( crontrol_uninstall_pro_in_use() ) {
	return;
}

if ( ! function_exists( 'crontrol_uninstall_site' ) ) {
	/**
	 * Removes the per-site logging options and scheduled maintenance events for the current site.
	 */
	function crontrol_uninstall_site(): void {
		delete_option( 'crontrol_log' );
		delete_option( 'crontrol_log_all_hooks' );
		delete_option( 'crontrol_retired_pro_notice' );
		delete_option( 'crontrol_alerts' );
		delete_option( 'crontrol_alerts_state' );
		delete_option( 'wp_crontrol_referred_plugins' );

		wp_clear_scheduled_hook( 'crontrol/metrics' );
		wp_clear_scheduled_hook( 'crontrol/maintenance' );
		wp_clear_scheduled_hook( 'crontrol/alerts_digest' );
	}
}

// The log tables are shared by every site, and on a multi-network install by every network. They hold
// the bulk of the data, so they go first, before the loop over every site.
foreach ( array( 'crontrol_event_logs', 'crontrol_event_metrics' ) as $crontrol_table ) {
	$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . esc_sql( $GLOBALS['wpdb']->base_prefix . $crontrol_table ) . '`' );
}

// The table versions and metrics cursors live in network options. They're cleared on every network,
// otherwise another network would think the dropped tables still exist and never recreate them.
$crontrol_network_options = array(
	'crontrol_event_logs_version',
	'crontrol_event_metrics_version',
	'crontrol_metrics_last_hour',
	'crontrol_metrics_last_day',
	'crontrol_retired_pro_notice',
	'_site_transient_crontrol_event_logs_retry',
	'_site_transient_timeout_crontrol_event_logs_retry',
	'_site_transient_crontrol_event_metrics_retry',
	'_site_transient_timeout_crontrol_event_metrics_retry',
);

$crontrol_network_ids = is_multisite()
	? (array) get_networks(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_network_id() );

foreach ( $crontrol_network_ids as $crontrol_network_id ) {
	foreach ( $crontrol_network_options as $crontrol_option ) {
		delete_network_option( (int) $crontrol_network_id, $crontrol_option );
	}
}

delete_site_transient( 'crontrol_event_logs_retry' );
delete_site_transient( 'crontrol_event_metrics_retry' );

if ( is_multisite() ) {
	$crontrol_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $crontrol_site_ids as $crontrol_site_id ) {
		switch_to_blog( (int) $crontrol_site_id );
		crontrol_uninstall_site();
		restore_current_blog();
	}
} else {
	crontrol_uninstall_site();
}


// Forget the dismissal of the logging pointer, which WordPress keeps in a comma-separated list.
$crontrol_pointer_users = $GLOBALS['wpdb']->get_col(
	$GLOBALS['wpdb']->prepare(
		"SELECT user_id FROM {$GLOBALS['wpdb']->usermeta} WHERE meta_key = 'dismissed_wp_pointers' AND meta_value LIKE %s",
		'%' . $GLOBALS['wpdb']->esc_like( 'wp-crontrol-logs' ) . '%'
	)
);

foreach ( $crontrol_pointer_users as $crontrol_user_id ) {
	$crontrol_pointers = explode( ',', (string) get_user_meta( (int) $crontrol_user_id, 'dismissed_wp_pointers', true ) );
	$crontrol_pointers = array_diff( $crontrol_pointers, array( 'wp-crontrol-logs' ) );
	update_user_meta( (int) $crontrol_user_id, 'dismissed_wp_pointers', implode( ',', $crontrol_pointers ) );
}
