<?php declare(strict_types = 1);

namespace Crontrol\Tests;

/**
 * The log tables are shared by every network, so their schema markers must go on every network.
 *
 * @group ms-required
 */
class UninstallNetworksTest extends Test {
	public function testSchemaMarkersAreClearedOnEveryNetwork(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->site,
			array(
				'domain' => 'second-network.test',
				'path'   => '/',
			)
		);
		$second_network = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $second_network );
		clean_network_cache( $second_network );

		foreach ( array( get_current_network_id(), $second_network ) as $network_id ) {
			update_network_option( $network_id, 'crontrol_event_logs_version', 11 );
			update_network_option( $network_id, 'crontrol_event_metrics_version', 5 );
			update_network_option( $network_id, 'crontrol_metrics_last_hour', '2026-10-14 10:00:00' );
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-crontrol/wp-crontrol.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( array( get_current_network_id(), $second_network ) as $network_id ) {
			self::assertFalse( get_network_option( $network_id, 'crontrol_event_logs_version' ), "Network {$network_id}" );
			self::assertFalse( get_network_option( $network_id, 'crontrol_event_metrics_version' ), "Network {$network_id}" );
			self::assertFalse( get_network_option( $network_id, 'crontrol_metrics_last_hour' ), "Network {$network_id}" );
		}
	}
}
