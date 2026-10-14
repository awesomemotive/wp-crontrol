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
	delete_option( 'crontrol_retired_pro_notice' );

	wp_clear_scheduled_hook( 'crontrol/metrics' );
	wp_clear_scheduled_hook( 'crontrol/maintenance' );
}

// The log tables and their versions are shared by every site in a network. They hold the bulk of
// the data, so they go first, before the loop over every site.
foreach ( array( 'crontrol_event_logs', 'crontrol_event_metrics' ) as $crontrol_table ) {
	$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . esc_sql( $GLOBALS['wpdb']->base_prefix . $crontrol_table ) . '`' );
	delete_site_option( $crontrol_table . '_version' );
	delete_site_transient( $crontrol_table . '_retry' );
}

delete_site_option( 'crontrol_metrics_last_hour' );
delete_site_option( 'crontrol_metrics_last_day' );
delete_site_option( 'crontrol_retired_pro_notice' );

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


delete_metadata( 'user', 0, 'crontrol_dismissed_promos', '', true );
delete_metadata( 'user', 0, 'crontrol_seen_tabs', '', true );

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
