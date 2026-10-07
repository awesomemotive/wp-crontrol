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

/**
 * Removes the per-site logging options and scheduled maintenance events for the current site.
 */
function crontrol_uninstall_site(): void {
	delete_option( 'crontrol_log' );
	delete_option( 'crontrol_log_all_hooks' );

	wp_clear_scheduled_hook( 'crontrol/metrics' );
	wp_clear_scheduled_hook( 'crontrol/maintenance' );
}

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

// The log tables and their versions are shared by every site in a network.
foreach ( array( 'crontrol_event_logs', 'crontrol_event_metrics' ) as $crontrol_table ) {
	$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . esc_sql( $GLOBALS['wpdb']->base_prefix . $crontrol_table ) . '`' );
	delete_site_option( $crontrol_table . '_version' );
	delete_site_transient( $crontrol_table . '_retry' );
}

delete_metadata( 'user', 0, 'crontrol_dismissed_promos', '', true );
