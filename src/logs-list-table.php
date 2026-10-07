<?php
/**
 * Placeholder list table for cron logs.
 */

namespace Crontrol\Logs;

use stdClass;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Cron logs placeholder list table class.
 */
final class Table extends \WP_List_Table {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'crontrol-log',
			'plural'   => 'crontrol-logs',
			'ajax'     => false,
			'screen'   => 'crontrol-logs',
		) );
	}

	/**
	 * Prepares some generated placeholder items for the table.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$hooks = array_values(
			array_map(
				fn( $hook ) => $hook->hook,
				\Crontrol\Event\get()
			)
		);
		$hook_count = count( $hooks );
		$warning = '<span class="status-crontrol-warning"><span class="dashicons dashicons-warning"></span> 2</span>';
		$error = '<span class="status-crontrol-error"><span class="dashicons dashicons-warning"></span> 1</span>';

		for ( $i = 0; $i < 20; $i++ ) {
			$duration = wp_rand( 300, 9000 );
			$hook = $hooks[ wp_rand( 0, $hook_count - 1 ) ];
			$parts = explode( '_', $hook );

			$this->items[] = (object) [
				'crontrol_time' => strtotime( 'now' ) - ( ( $i + 1 ) * 3100 ),
				'crontrol_hook' => $hook . '_example',
				'crontrol_action' => end( $parts ) . '_example()',
				'crontrol_duration' => $duration,
				'crontrol_memory' => $duration * 10000,
				'crontrol_warnings' => $duration > 7500 ? $warning : '',
				'crontrol_errors' => $i === 2 ? $error : '',
			];
		}
	}

	/**
	 * Returns an array of column names for the table.
	 *
	 * @return array<string,string> Array of column names keyed by their ID.
	 */
	public function get_columns() {
		return array(
			'crontrol_time' => 'Event Time',
			'crontrol_hook' => 'Hook',
			'crontrol_action' => 'Action',
			'crontrol_duration' => 'Duration',
			'crontrol_memory' => 'Memory Usage',
			'crontrol_warnings' => 'Warnings',
			'crontrol_errors' => 'Errors',
		);
	}

	/**
	 * Generates content for a single row of the table.
	 *
	 * @param stdClass $event The current event.
	 * @return void
	 */
	public function single_row( $event ) {
		echo '<tr>';
		$this->single_row_columns( $event );
		echo '</tr>';
	}

	protected function column_crontrol_time( $event ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$time = date( $format, $event->crontrol_time );
		$ago = sprintf(
			'%s ago',
			\Crontrol\interval( time() - $event->crontrol_time )
		);

		return sprintf(
			'%s<br>%s',
			$time,
			esc_html( $ago )
		);
	}

	protected function column_crontrol_duration( $event ) {
		return number_format_i18n( $event->crontrol_duration / 1000, 1 ) . 's';
	}

	protected function column_crontrol_memory( $event ) {
		return size_format( $event->crontrol_memory, 1 );
	}

	protected function column_default( $event, $name ) {
		return $event->$name;
	}
}
