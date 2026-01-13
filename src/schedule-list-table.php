<?php
/**
 * List table for cron schedules.
 */

namespace Crontrol;

use Crontrol\Schedule\Schedule;
use Crontrol\Schedule\CoreSchedule;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Cron schedule list table class.
 */
class Schedule_List_Table extends \WP_List_Table {
	/**
	 * Array of Schedule instances for the current page.
	 *
	 * @var array<string,Schedule>
	 */
	public $items;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'crontrol-schedule',
			'plural'   => 'crontrol-schedules',
			'ajax'     => false,
			'screen'   => 'crontrol-schedules',
		) );
	}

	/**
	 * Gets the name of the primary column.
	 *
	 * @return string The name of the primary column.
	 */
	#[\Override]
	protected function get_primary_column_name() {
		return 'crontrol_name';
	}

	/**
	 * Prepares the list table items and arguments.
	 *
	 * @return void
	 */
	#[\Override]
	public function prepare_items() {
		$schedules = \Crontrol\Schedule\get();
		$count     = count( $schedules );

		$this->items = $schedules;

		$this->set_pagination_args( array(
			'total_items' => $count,
			'per_page'    => -1,
			'total_pages' => 1,
		) );
	}

	/**
	 * Returns an array of column names for the table.
	 *
	 * @return array<string,string> Array of column names keyed by their ID.
	 */
	#[\Override]
	public function get_columns() {
		return array(
			'crontrol_icon'     => '',
			'crontrol_name'     => esc_html__( 'Internal Name', 'wp-crontrol' ),
			'crontrol_interval' => esc_html__( 'Interval', 'wp-crontrol' ),
			'crontrol_display'  => esc_html__( 'Display Name', 'wp-crontrol' ),
		);
	}

	/**
	 * Returns an array of CSS class names for the table.
	 *
	 * @return array<int,string> Array of class names.
	 */
	#[\Override]
	protected function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', 'table-view-list', $this->_args['plural'] );
	}

	/**
	 * Generates and displays row action links for the table.
	 *
	 * @param Schedule $schedule    The schedule for the current row.
	 * @param string   $column_name Current column name.
	 * @param string   $primary     Primary column name.
	 * @return string The row actions HTML.
	 */
	#[\Override]
	protected function handle_row_actions( $schedule, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$links = array();

		if ( $schedule->persistent() ) {
			$links[] = "<span class='crontrol-in-use'>" . esc_html( $schedule->get_persistent_message() ) . '</span>';
			return $this->row_actions( $links );
		}

		if ( ! $schedule->deletable() ) {
			// Permission-based: no message shown
			return $this->row_actions( $links );
		}

		$link = add_query_arg( array(
			'page'            => 'wp-crontrol-schedules',
			'crontrol_action' => 'delete-schedule',
			'crontrol_id'     => rawurlencode( $schedule->name ),
		), admin_url( 'options-general.php' ) );
		$link = wp_nonce_url( $link, 'crontrol-delete-schedule_' . $schedule->name );

		$links[] = "<span class='delete'><a href='" . esc_url( $link ) . "'>" . esc_html__( 'Delete', 'wp-crontrol' ) . '</a></span>';

		return $this->row_actions( $links );
	}

	/**
	 * Returns the output for the icon cell of a table row.
	 *
	 * @param Schedule $schedule The schedule for the current row.
	 */
	protected function column_crontrol_icon( Schedule $schedule ): string {
		if ( $schedule instanceof CoreSchedule ) {
			return sprintf(
				'<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
				<span class="screen-reader-text">%s</span>',
				esc_html( $schedule->get_persistent_message() )
			);
		}

		return '';
	}

	/**
	 * Returns the output for the schedule name cell of a table row.
	 *
	 * @param Schedule $schedule The schedule for the current row.
	 */
	protected function column_crontrol_name( Schedule $schedule ): string {
		return esc_html( $schedule->name );
	}

	/**
	 * Returns the output for the interval cell of a table row.
	 *
	 * @param Schedule $schedule The schedule for the current row.
	 */
	protected function column_crontrol_interval( Schedule $schedule ): string {
		$interval = sprintf(
			'%s (%s)',
			esc_html( "{$schedule->interval}" ),
			esc_html( interval( $schedule->interval, true ) )
		);

		if ( $schedule->is_too_frequent() ) {
			$interval .= sprintf(
				'<span class="status-crontrol-warning"><br><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</span>',
				sprintf(
					/* translators: 1: The name of the configuration constant, 2: The value of the configuration constant */
					esc_html__( 'This interval is less than the %1$s constant which is set to %2$s seconds. Events that use it may not run on time.', 'wp-crontrol' ),
					'<code>WP_CRON_LOCK_TIMEOUT</code>',
					intval( WP_CRON_LOCK_TIMEOUT )
				)
			);
		}

		return $interval;
	}

	/**
	 * Returns the output for the display name cell of a table row.
	 *
	 * @param Schedule $schedule The schedule for the current row.
	 */
	protected function column_crontrol_display( Schedule $schedule ): string {
		return esc_html( $schedule->display );
	}

	/**
	 * Outputs a message when there are no items to show in the table.
	 *
	 * @return void
	 */
	#[\Override]
	public function no_items() {
		esc_html_e( 'There are no schedules.', 'wp-crontrol' );
	}
}
