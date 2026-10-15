<?php

namespace Crontrol\Pro\CLI;

use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\QueryBuilder;
use DateTimeImmutable;
use DateTimeZone;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage cron event logs.
 */
class LogsCommand extends WP_CLI_Command {

	/**
	 * List cron event logs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * options:
	 *   - completed
	 *   - errored
	 *   - running
	 * ---
	 *
	 * [--limit=<number>]
	 * : Number of records to retrieve.
	 *
	 * [--before=<id>]
	 * : Show records with ID before this value.
	 *
	 * [--after=<id>]
	 * : Show records with ID after this value.
	 *
	 * [--network]
	 * : Query logs from all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # List latest 20 logs
	 *     wp crontrol logs list
	 *
	 *     # List only errored logs
	 *     wp crontrol logs list --status=errored
	 *
	 *     # List logs with custom fields
	 *     wp crontrol logs list --fields=hook,status,errors
	 *
	 *     # Get count of all logs
	 *     wp crontrol logs list --format=count
	 *
	 *     # Export to CSV
	 *     wp crontrol logs list --format=csv > logs.csv
	 *
	 *     # List logs from all sites in network
	 *     wp crontrol logs list --network
	 *
	 * @param list<string> $args Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ): void {
		if ( ! \Crontrol\Pro\tables_ready() ) {
			\WP_CLI::error( 'Cron event logging has not been turned on for this site, so there are no logs.' );
		}

		$default_fields = 'ID,started_at,hook,status,duration,memory,db_queries';

		if ( is_multisite() ) {
			$default_fields = 'site_id,' . $default_fields;
		}

		$limit = (int) ( $assoc_args['limit'] ?? 20 );
		$status = $assoc_args['status'] ?? '';
		$before = isset( $assoc_args['before'] ) ? (int) $assoc_args['before'] : 0;
		$after = isset( $assoc_args['after'] ) ? (int) $assoc_args['after'] : 0;
		$format = $assoc_args['format'] ?? 'table';
		$fields = $assoc_args['fields'] ?? $default_fields;
		$network = isset( $assoc_args['network'] );

		// Build query
		$query = EventRunModel::query();

		// Query network-wide if requested
		if ( $network ) {
			$query->query_network();
		}

		if ( $status ) {
			$query->where( 'status', QueryBuilder::OPERATOR_EQUALS, $status );
		}

		if ( 'count' === $format ) {
			$count_query = EventRunModel::query();
			if ( $network ) {
				$count_query->query_network();
			}
			if ( $status ) {
				$count_query->where( 'status', QueryBuilder::OPERATOR_EQUALS, $status );
			}
			WP_CLI::line( (string) $count_query->count() );
			return;
		}

		if ( $after ) {
			$raw_items = $query->after( $after )->per_page( $limit )->get_raw();
		} else {
			$raw_items = $query->before( $before )->per_page( $limit )->get_raw();
		}

		if ( empty( $raw_items ) ) {
			WP_CLI::warning( 'No logs found.' );
			return;
		}

		// Handle different output formats
		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $raw_items, 'ID' ) ) );
		} else {
			\WP_CLI\Utils\format_items( $format, $raw_items, $fields );
		}
	}

	/**
	 * Clear all cron event logs.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * [--network]
	 * : Clear logs from all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all logs with confirmation
	 *     wp crontrol logs clear
	 *
	 *     # Clear all logs without confirmation
	 *     wp crontrol logs clear --yes
	 *
	 *     # Clear logs from all sites in network
	 *     wp crontrol logs clear --network --yes
	 *
	 * @param list<string> $args Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ): void {
		if ( ! \Crontrol\Pro\tables_ready() ) {
			\WP_CLI::error( 'Cron event logging has not been turned on for this site, so there are no logs.' );
		}

		// Suppress unused parameter warning
		unset( $args );
		$network = isset( $assoc_args['network'] );

		$count_query = EventRunModel::query();
		if ( $network ) {
			$count_query->query_network();
		}
		$total_logs = $count_query->count();

		if ( 0 === $total_logs ) {
			WP_CLI::warning( 'No logs to clear.' );
			return;
		}

		$confirm_message = $network && is_multisite()
			? sprintf( 'Are you sure you want to clear all %s logs from all sites in the network?', number_format( $total_logs ) )
			: sprintf( 'Are you sure you want to clear all %s logs?', number_format( $total_logs ) );

		WP_CLI::confirm( $confirm_message, $assoc_args );

		// Clear the logs
		$success = EventRunModel::clear_records( $network );

		if ( $success ) {
			WP_CLI::success( sprintf( 'Cleared %s log entries.', number_format( $total_logs ) ) );
		} else {
			WP_CLI::error( 'Failed to clear log entries.' );
		}
	}
}
