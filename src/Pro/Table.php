<?php
/**
 * Placeholder list table for cron logs.
 */

namespace Crontrol\Pro\Logs;

use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\Logger;
use Crontrol\Pro\QueryBuilder;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Cron logs placeholder list table class.
 */
final class Table extends \WP_List_Table {
	/**
	 * The current list of items.
	 *
	 * @var array<int, EventRun>
	 */
	public $items;

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
	 * Prepares items for the table.
	 *
	 * @return void
	 */
	#[\Override]
	public function prepare_items() {
		// If logs were just cleared, enforce an empty state in the table even if a
		// cron event fired between the logs being cleared and the redirect completing.
		if ( isset( $_GET['crontrol_pro_message'] ) && absint( $_GET['crontrol_pro_message'] ) === \Crontrol\Pro\MESSAGE_LOGS_CLEARED ) {
			$this->items = array();
			$this->set_pagination_args( array(
				'total_items' => 0,
				'per_page' => 20,
				'start' => 0,
				'first_id' => 0,
				'last_id' => 0,
			) );
			return;
		}

		$per_page = 20;
		$before = $this->get_before();
		$after = $this->get_after();
		$start = $this->get_start();
		$status = $this->get_status();
		$hook = $this->get_hook();

		// Build query with filters if provided
		$query = EventRunModel::query();
		if ( $status ) {
			$query->where( 'status', QueryBuilder::OPERATOR_EQUALS, $status );
		}
		if ( $hook ) {
			$query->where( 'hook', QueryBuilder::OPERATOR_EQUALS, $hook );
		}

		if ( $after ) {
			$this->items = $query->after( $after )->per_page( $per_page )->get();
		} else {
			$this->items = $query->before( $before )->per_page( $per_page )->get();
		}

		// Count with same filters
		$total_items = $query->count( $start );

		$first_item = $this->items[0] ?? null;
		$last_item = $this->items[ count( $this->items ) - 1 ] ?? null;

		if ( ! $start ) {
			$start = $first_item ? $first_item->ID : 0;
		}

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page' => $per_page,
			'start' => $start,
			'first_id' => $first_item ? $first_item->ID : 0,
			'last_id' => $last_item ? $last_item->ID : 0,
		) );
	}

	private function get_start(): int {
		return isset( $_GET['crontrol_start'] ) ? absint( $_GET['crontrol_start'] ) : 0;
	}

	private function get_before(): int {
		return isset( $_GET['crontrol_before'] ) ? absint( $_GET['crontrol_before'] ) : 0;
	}

	private function get_after(): int {
		return isset( $_GET['crontrol_after'] ) ? absint( $_GET['crontrol_after'] ) : 0;
	}

	private function get_status(): string {
		return isset( $_GET['crontrol_status'] ) ? sanitize_text_field( $_GET['crontrol_status'] ) : '';
	}

	private function get_hook(): string {
		return isset( $_GET['crontrol_hook'] ) ? sanitize_text_field( $_GET['crontrol_hook'] ) : '';
	}

	private function get_paged(): int {
		return isset( $_GET['crontrol_paged'] ) ? absint( $_GET['crontrol_paged'] ) : 1;
	}

	/**
	 * Get status filter links for display above the table.
	 *
	 * @return array<string, string>
	 */
	#[\Override]
	protected function get_views() {
		$current_status = $this->get_status();
		$current_hook = $this->get_hook();
		$current_url = remove_query_arg( [ 'crontrol_status', 'crontrol_before', 'crontrol_after', 'crontrol_start', 'crontrol_paged' ] );

		// Maintain hook filter in view links
		if ( $current_hook ) {
			$current_url = add_query_arg( 'crontrol_hook', rawurlencode( $current_hook ), $current_url );
		}

		$views = [];

		// All link
		$class = empty( $current_status ) ? 'current' : '';
		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( $current_url ),
			$class,
			esc_html__( 'All', 'wp-crontrol' )
		);

		// Status-specific links
		$statuses = [
			EventRun::STATUS_COMPLETED => __( 'Completed', 'wp-crontrol' ),
			EventRun::STATUS_ERRORED => __( 'Errored', 'wp-crontrol' ),
			EventRun::STATUS_RUNNING => __( 'Running', 'wp-crontrol' ),
		];

		foreach ( $statuses as $status => $label ) {
			$class = ( $current_status === $status ) ? 'current' : '';
			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'crontrol_status', $status, $current_url ) ),
				$class,
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * Displays the pagination.
	 *
	 * @param string $which The location of the pagination: Either 'top' or 'bottom'.
	 * @return void
	 */
	#[\Override]
	protected function pagination( $which ) {
		$total_pages = $this->_pagination_args['total_pages'];
		$total_items = $this->_pagination_args['total_items'];

		if ( 'top' === $which && $total_pages > 1 ) {
			// @TODO screenreader config
			$this->screen->render_screen_reader_content( 'heading_pagination' );
		}

		if ( $total_items >= EventRunModel::COUNT_LIMIT ) {
			$total_text = sprintf(
				/* translators: %s: Number of items. */
				_n( 'More than %s item', 'More than %s items', $total_items, 'wp-crontrol' ),
				number_format_i18n( $total_items )
			);
		} else {
			$total_text = sprintf(
				/* translators: %s: Number of items. */
				_n( '%s item', '%s items', $total_items, 'wp-crontrol' ),
				number_format_i18n( $total_items )
			);
		}

		printf(
			'<div class="tablenav-pages"><span class="displaying-num">%s</span><span class="pagination-links">%s</span></div>',
			esc_html( $total_text ),
			implode( "\n", $this->get_keyset_pagination_links() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Returns the keyset-based pagination links.
	 *
	 * @return array<int, string>
	 */
	protected function get_keyset_pagination_links(): array {
		$total_pages = $this->_pagination_args['total_pages'];
		$total_items = $this->_pagination_args['total_items'];
		$start       = $this->_pagination_args['start'];
		$first_id    = $this->_pagination_args['first_id'];
		$last_id     = $this->_pagination_args['last_id'];
		$pagenum     = $this->get_paged();

		if ( $total_pages < 2 ) {
			return array();
		}

		$removable_query_args   = wp_removable_query_args();
		$removable_query_args[] = 'crontrol_before';
		$removable_query_args[] = 'crontrol_after';
		$removable_query_args[] = 'crontrol_start';
		$removable_query_args[] = 'crontrol_paged';

		$current_url   = remove_query_arg( $removable_query_args );
		$page_links    = array();
		$disable_first = false;
		$disable_prev  = false;
		$disable_next  = false;

		if ( $pagenum <= 1 ) {
			$disable_first = true;
			$disable_prev  = true;
		}
		if ( $total_pages === $pagenum ) {
			$disable_next = true;
		}

		if ( $disable_first ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
		} else {
			$page_links[] = sprintf(
				'<a class="first-page button" href="%s">' .
					'<span class="screen-reader-text">%s</span>' .
					'<span aria-hidden="true">&laquo;</span>' .
				'</a>',
				esc_url( $current_url ),
				/* translators: Hidden accessibility text. */
				esc_html__( 'First page', 'wp-crontrol' )
			);
		}

		if ( $disable_prev ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
		} else {
			$args = [
				'crontrol_start' => $start,
				'crontrol_paged' => $pagenum - 1,
				'crontrol_after' => $first_id,
			];
			$page_links[] = sprintf(
				'<a class="prev-page button" href="%s">' .
					'<span class="screen-reader-text">%s</span>' .
					'<span aria-hidden="true">&lsaquo;</span>' .
				'</a>',
				esc_url( add_query_arg( $args, $current_url ) ),
				/* translators: Hidden accessibility text. */
				esc_html__( 'Previous page', 'wp-crontrol' )
			);
		}

		if ( $total_items >= EventRunModel::COUNT_LIMIT ) {
			$total_text = number_format_i18n( $total_pages ) . '+';
		} else {
			$total_text = number_format_i18n( $total_pages );
		}

		$page_links[] = sprintf(
			/* translators: 1: Current page, 2: Total pages. */
			_x( '%1$s of %2$s', 'paging', 'wp-crontrol' ),
			$pagenum,
			sprintf(
				'<span class="total-pages">%s</span>',
				$total_text
			)
		);

		if ( $disable_next ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
		} else {
			$args = [
				'crontrol_start' => $start,
				'crontrol_paged' => $pagenum + 1,
				'crontrol_before' => $last_id,
			];
			$page_links[] = sprintf(
				'<a class="next-page button" href="%s">' .
					'<span class="screen-reader-text">%s</span>' .
					'<span aria-hidden="true">&rsaquo;</span>' .
				'</a>',
				esc_url( add_query_arg( $args, $current_url ) ),
				/* translators: Hidden accessibility text. */
				esc_html__( 'Next page', 'wp-crontrol' )
			);
		}

		return $page_links;
	}

	/**
	 * Returns an array of column names for the table.
	 *
	 * @return array<string,string> Array of column names keyed by their ID.
	 */
	#[\Override]
	public function get_columns() {
		return array(
			'crontrol_time' => __( 'Started at', 'wp-crontrol' ),
			'crontrol_hook' => __( 'Hook', 'wp-crontrol' ),
			'crontrol_actions' => __( 'Action', 'wp-crontrol' ),
			'crontrol_status' => __( 'Status', 'wp-crontrol' ),
			'crontrol_duration' => __( 'Duration', 'wp-crontrol' ),
			'crontrol_view' => __( 'View', 'wp-crontrol' ),
		);
	}

	protected function column_crontrol_time( EventRun $event ): string {
		$timestamp = $event->started_at->getTimestamp();
		$time = \Crontrol\time_element( $timestamp );
		$ago = sprintf(
			/* translators: %s: Time period, for example "8 minutes" */
			__( '%s ago', 'wp-crontrol' ),
			\Crontrol\interval( time() - $timestamp )
		);

		return sprintf(
			'%s<br>%s',
			esc_html( $ago ),
			$time,
		);
	}

	protected function column_crontrol_hook( EventRun $event ): string {
		$output = \Crontrol\Event\get_hook_name_label( $event->hook, $event->args );

		// PHP and URL cron events describe themselves through the label above. Their raw
		// arguments hold code, URLs, and integrity hashes that should not be dumped here.
		if (
			\Crontrol\Event\PHPCronEvent::HOOK_NAME === $event->hook
			|| \Crontrol\Event\URLCronEvent::HOOK_NAME === $event->hook
		) {
			return $output;
		}

		if ( ! empty( $event->args ) ) {
			$output .= sprintf(
				'<br><details><summary>%s</summary><pre>%s</pre></details>',
				esc_html__( 'View arguments', 'wp-crontrol' ),
				esc_html( print_r( $event->args, true ) )
			);
		}

		return $output;
	}

	protected function column_crontrol_actions( EventRun $event ): string {
		if ( ! $event->actions ) {
			$help = sprintf(
				'<a href="%s">%s</a>',
				'https://wp-crontrol.com/help/no-action-cron-events/',
				esc_html__( 'Help', 'wp-crontrol' )
			);
			return sprintf(
				'<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %1$s</span> (%2$s)',
				esc_html__( 'None', 'wp-crontrol' ),
				$help
			);
		}

		$actions = array_filter( array_map( 'trim', $event->actions ) );
		$callbacks = array_map( static function ( string $action ): string {
			return '<code>' . esc_html( $action ) . '</code>';
		}, $actions );

		if ( count( $callbacks ) === 1 ) {
			return $callbacks[0];
		}

		return '<ol><li>' . implode( '</li><li>', $callbacks ) . '</li></ol>';
	}

	protected function column_crontrol_status( EventRun $event ): string {
		if ( ! $event->actions ) {
			return '';
		}

		if ( $event->is_completed() ) {
			return '<div class="crontrol-status-completed"><span class="dashicons dashicons-yes"></span>&nbsp;' . esc_html__( 'Completed', 'wp-crontrol' ) . '</div>';
		}

		if ( $event->is_errored() ) {
			return '<span class="dashicons dashicons-no"></span>&nbsp;' . esc_html__( 'Errored', 'wp-crontrol' )
				. '<br><a class="crontrol-ask-ai" href="' . esc_url( admin_url( 'tools.php?page=wp-crontrol-ai' ) ) . '">' . esc_html__( 'Ask your AI assistant why', 'wp-crontrol' ) . '</a>';
		}

		return '<span class="dashicons dashicons-clock crontrol-rotating"></span>&nbsp;' . esc_html__( 'Running', 'wp-crontrol' );
	}

	protected function column_crontrol_duration( EventRun $event ): string {
		if ( ! $event->actions ) {
			return '';
		}

		$ms = $event->get_duration();

		$formatted = esc_html( number_format( $ms / 1000, 3 ) . 's' );

		if ( $ms > ( MINUTE_IN_SECONDS * 1000 ) ) {
			$formatted .= sprintf(
				'<br>(%s)',
				esc_html( \Crontrol\interval( $ms / 1000 ) )
			);
		}

		if ( $event->is_running() ) {
			return sprintf(
				'<span class="dashicons dashicons-clock crontrol-rotating"></span>&nbsp;%s',
				$formatted,
			);
		}

		return $formatted;
	}

	protected function column_crontrol_db_queries( EventRun $event ): string {
		return esc_html( number_format( $event->db_queries ) );
	}

	protected function column_crontrol_https( EventRun $event ): string {
		return esc_html( number_format( $event->https ) );
	}

	protected function column_crontrol_emails( EventRun $event ): string {
		return esc_html( number_format( $event->emails ) );
	}

	protected function column_crontrol_view( EventRun $event ): string {
		if ( ! $event->is_finished() ) {
			return '';
		}

		$dialog_id = sprintf( 'crontrol-run-%d', $event->ID );

		$button = sprintf(
			'<button type="button" class="button button-small" data-crontrol-view-dialog="%s">%s</button>',
			esc_attr( $dialog_id ),
			esc_html__( 'View Details', 'wp-crontrol' )
		);

		return $button . $this->run_details_dialog( $event, $dialog_id );
	}

	/**
	 * Builds a native HTML dialog containing every detail recorded for an event run.
	 *
	 * @param EventRun $event     The event run to render.
	 * @param string   $dialog_id The DOM ID to assign to the dialog element.
	 */
	private function run_details_dialog( EventRun $event, string $dialog_id ): string {
		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		/**
		 * Each row is a label paired with already-escaped HTML for its value.
		 *
		 * @var array<int, array{0: string, 1: string}> $rows
		 */
		$rows = array();

		$started_at_utc = gmdate( 'Y-m-d H:i:s', $event->started_at->getTimestamp() );
		$started_at = esc_html( get_date_from_gmt( $started_at_utc, $datetime_format ) );

		// If the site is not using UTC, also show the time in UTC.
		if ( '+00:00' !== get_date_from_gmt( $started_at_utc, 'P' ) ) {
			$started_at .= sprintf(
				' (%s)',
				esc_html( sprintf(
					/* translators: %s: A date and time in UTC */
					__( '%s UTC', 'wp-crontrol' ),
					$event->started_at->format( $datetime_format )
				) )
			);
		}

		$rows[] = array( __( 'Started at', 'wp-crontrol' ), $started_at );
		$rows[] = array( __( 'Hook', 'wp-crontrol' ), esc_html( $event->hook ) );

		$args_display = \Crontrol\Event\get_hook_args_display( $event->hook, $event->args );

		if ( null !== $args_display ) {
			$rows[] = array( __( 'Arguments', 'wp-crontrol' ), $args_display );
		} elseif ( ! empty( $event->args ) ) {
			$rows[] = array( __( 'Arguments', 'wp-crontrol' ), '<pre>' . esc_html( print_r( $event->args, true ) ) . '</pre>' );
		} else {
			$rows[] = array( __( 'Arguments', 'wp-crontrol' ), esc_html__( 'None', 'wp-crontrol' ) );
		}

		$actions = array_filter( array_map( 'trim', $event->actions ) );
		if ( $actions ) {
			$callbacks = array_map( static function ( string $action ): string {
				return '<code>' . esc_html( $action ) . '</code>';
			}, $actions );
			$rows[] = array( __( 'Action', 'wp-crontrol' ), implode( '<br>', $callbacks ) );
		} else {
			$rows[] = array( __( 'Action', 'wp-crontrol' ), esc_html__( 'None', 'wp-crontrol' ) );
		}

		$body = '';
		foreach ( $rows as $row ) {
			$body .= sprintf(
				'<dt>%s</dt><dd>%s</dd>',
				esc_html( $row[0] ),
				$row[1] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped where it is built above.
			);
		}

		/**
		 * Headline metrics rendered as stat boxes below the details list.
		 *
		 * @var array<int, array{0: string, 1: string}> $metrics
		 */
		$metrics = array();

		if ( $event->is_finished() ) {
			$metrics[] = array( __( 'Duration', 'wp-crontrol' ), number_format( $event->duration / 1000, 3 ) . 's' );
		}

		$metrics[] = array( __( 'DB queries', 'wp-crontrol' ), number_format( $event->db_queries ) );
		$metrics[] = array( __( 'HTTP API calls', 'wp-crontrol' ), number_format( $event->https ) );
		$metrics[] = array( __( 'Emails sent', 'wp-crontrol' ), number_format( $event->emails ) );

		if ( $event->mail_failed > 0 ) {
			$metrics[] = array( __( 'Emails failed', 'wp-crontrol' ), number_format( $event->mail_failed ) );
		}

		if ( $event->memory > 0 ) {
			$metrics[] = array( __( 'Peak memory', 'wp-crontrol' ), size_format( $event->memory ) );
		}

		$metrics_html = '';
		foreach ( $metrics as $metric ) {
			$metrics_html .= sprintf(
				'<div class="crontrol-run-metric"><span class="crontrol-run-metric-label">%2$s</span><span class="crontrol-run-metric-value">%1$s</span></div>',
				esc_html( $metric[1] ),
				esc_html( $metric[0] )
			);
		}

		$lists_html = $this->run_details_notes( $event );
		$lists_html .= $this->run_details_list(
			__( 'Errors', 'wp-crontrol' ),
			$event->errors,
			__( 'None', 'wp-crontrol' ),
			'crontrol-run-dialog-list-errors'
		);
		$lists_html .= $this->run_details_list(
			__( 'Log messages', 'wp-crontrol' ),
			$event->logs,
			__( 'None', 'wp-crontrol' ),
			'crontrol-run-dialog-list-logs'
		);

		return sprintf(
			'<dialog id="%1$s" class="crontrol-run-dialog">' .
				'<form method="dialog">' .
					'<header class="crontrol-run-dialog-header">' .
						'<h2>%2$s</h2>' .
						'<button type="submit" class="button-link crontrol-run-dialog-close" aria-label="%3$s">&times;</button>' .
					'</header>' .
					'<div class="crontrol-run-dialog-body">' .
						'<dl class="crontrol-run-dialog-details">%4$s</dl>' .
						'<div class="crontrol-run-dialog-metrics">%5$s</div>' .
						'%6$s' .
					'</div>' .
				'</form>' .
			'</dialog>',
			esc_attr( $dialog_id ),
			esc_html__( 'Run details', 'wp-crontrol' ),
			esc_attr__( 'Close', 'wp-crontrol' ),
			$body, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values above.
			$metrics_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values above.
			$lists_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values below.
		);
	}

	/**
	 * Builds the notes at the top of the run details: a failed email, and where to get help with a failure.
	 *
	 * @param EventRun $event The event run.
	 */
	private function run_details_notes( EventRun $event ): string {
		$notes = '';

		if ( $event->mail_failed > 0 ) {
			$notes .= '<p><strong>' . esc_html( _n( 'An email from this run failed to send.', 'Emails from this run failed to send.', $event->mail_failed, 'wp-crontrol' ) ) . '</strong> ';

			$smtp = \Crontrol\Promos\get_plugin( 'wp-mail-smtp' );

			if ( $smtp && ! $smtp['active'] ) {
				$notes .= esc_html__( 'Many hosts can’t send email reliably from WordPress. WP Mail SMTP sends it through a real mail provider instead.', 'wp-crontrol' )
					. '</p><p>' . \Crontrol\Promos\plugin_button( 'wp-mail-smtp', $smtp );
			}

			$notes .= '</p>';
		}

		if ( $event->is_errored() ) {
			$notes .= '<p>' . sprintf(
				/* translators: %s: URL of the Cron AI screen */
				wp_kses( __( 'Not sure why this failed? <a href="%s">Ask your AI assistant</a> to read this log and the code behind the event.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'tools.php?page=wp-crontrol-ai' ) )
			) . '</p>';
		}

		return $notes ? '<div class="crontrol-run-dialog-notes">' . $notes . '</div>' : '';
	}

	/**
	 * Builds a titled list section for the run details dialog.
	 *
	 * Each entry is rendered as a two-column grid: a colour-coded level indicator
	 * with a dashicon, followed by the message. When there are no entries, a single
	 * muted placeholder item is shown instead.
	 *
	 * @param string $title The heading for the section.
	 * @param list<array{level: string, message: string}> $entries The log entries to render as list items.
	 * @param string $empty The placeholder message shown when there are no entries.
	 * @param string $class Optional extra CSS class for the section wrapper.
	 */
	private function run_details_list( string $title, array $entries, string $empty, string $class = '' ): string {
		if ( empty( $entries ) ) {
			$items = '<li class="crontrol-run-dialog-list-empty">' . esc_html( $empty ) . '</li>';
		} else {
			$items = '';
			foreach ( $entries as $entry ) {
				$level = $entry['level'];
				$dashicon = self::level_dashicon( $level );

				$items .= sprintf(
					'<li class="crontrol-run-dialog-list-item crontrol-level-%1$s">' .
						'<span class="crontrol-run-dialog-list-level"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>' .
						'<span class="crontrol-run-dialog-list-message">%4$s</span>' .
					'</li>',
					esc_attr( $level ),
					esc_attr( $dashicon ),
					esc_html( ucfirst( $level ) ),
					esc_html( $entry['message'] )
				);
			}
		}

		$classes = trim( 'crontrol-run-dialog-list ' . $class );

		return sprintf(
			'<div class="%s"><h3>%s</h3><ul>%s</ul></div>',
			esc_attr( $classes ),
			esc_html( $title ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped above.
		);
	}

	/**
	 * Returns the dashicon class used to represent a given log level.
	 *
	 * @param string $level The log level.
	 * @return string The dashicons class name.
	 */
	private static function level_dashicon( string $level ): string {
		switch ( $level ) {
			case Logger::EMERGENCY:
			case Logger::ALERT:
			case Logger::CRITICAL:
			case Logger::ERROR:
			case Logger::WARNING:
				return 'dashicons-warning';
			case Logger::NOTICE:
			case Logger::INFO:
			case Logger::DEBUG:
			default:
				return 'dashicons-info-outline';
		}
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which One of 'top' or 'bottom' to indicate the position on the screen.
	 *
	 * @return void
	 */
	#[\Override]
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$this->hook_filter_form();
		$this->clear_logs_form();
	}

	/**
	 * Renders the hook filter dropdown as a GET form so the selected hook is reflected
	 * in the URL query string.
	 *
	 * The dropdown lists every hook that currently has logging enabled, plus every hook
	 * that is present in the logs table.
	 */
	private function hook_filter_form(): void {
		$enabled = \Crontrol\Pro\Log::get_enabled_hooks();
		$in_logs = EventRunModel::get_unique_hooks();

		$hooks = array_unique( array_merge( $enabled, $in_logs ) );
		sort( $hooks );

		if ( ! $hooks ) {
			return;
		}

		$current_hook = $this->get_hook();
		$current_status = $this->get_status();

		echo '<form method="get" class="alignleft actions">';
		echo '<input type="hidden" name="page" value="wp-crontrol-logs" />';

		if ( '' !== $current_status ) {
			printf(
				'<input type="hidden" name="crontrol_status" value="%s" />',
				esc_attr( $current_status )
			);
		}

		printf(
			'<label class="screen-reader-text" for="crontrol-hook-filter">%s</label>',
			esc_html__( 'Filter by hook', 'wp-crontrol' )
		);

		// Group the hooks into the WP Crontrol event hooks, custom hooks, and WordPress core hooks.
		$crontrol_hooks = array(
			\Crontrol\Event\URLCronEvent::HOOK_NAME => __( 'URL cron event', 'wp-crontrol' ),
			\Crontrol\Event\PHPCronEvent::HOOK_NAME => __( 'PHP cron event', 'wp-crontrol' ),
		);
		$core_hooks = \Crontrol\get_all_core_hooks();
		$crontrol = array_intersect_key( $crontrol_hooks, array_flip( $hooks ) );
		$custom = array_values( array_diff( $hooks, $core_hooks, array_keys( $crontrol_hooks ) ) );
		$core = array_values( array_intersect( $hooks, $core_hooks ) );

		echo '<select name="crontrol_hook" id="crontrol-hook-filter">';
		printf(
			'<option value=""%s>%s</option>',
			selected( $current_hook, '', false ),
			esc_html__( 'All hooks', 'wp-crontrol' )
		);

		if ( $custom ) {
			echo '<hr/>';
			$this->render_hook_options( $custom, $current_hook );
		}

		if ( $crontrol ) {
			echo '<hr/>';
			foreach ( $crontrol as $hook => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $hook ),
					selected( $current_hook, $hook, false ),
					esc_html( $label )
				);
			}
		}

		if ( $core ) {
			echo '<hr/>';
			$this->render_hook_options( $core, $current_hook );
		}

		echo '</select>';

		submit_button( __( 'Filter', 'wp-crontrol' ), '', '', false, array( 'id' => 'crontrol-hook-query-submit' ) );

		echo '</form>';
	}

	/**
	 * Renders a list of <option> elements for the hook filter dropdown.
	 *
	 * @param array<int,string> $hooks        The hook names to render.
	 * @param string            $current_hook The currently selected hook.
	 */
	private function render_hook_options( array $hooks, string $current_hook ): void {
		foreach ( $hooks as $hook ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $hook ),
				selected( $current_hook, $hook, false ),
				esc_html( $hook )
			);
		}
	}

	/**
	 * Renders the clear logs button as its own POST form.
	 *
	 * When a hook filter is active, the button clears the logs for that hook only and its
	 * label changes accordingly.
	 */
	private function clear_logs_form(): void {
		// Don't show the button when there are no logs to clear.
		if ( $this->get_pagination_arg( 'total_items' ) === 0 ) {
			return;
		}

		$current_hook = $this->get_hook();

		echo '<form method="post" class="alignleft actions">';
		wp_nonce_field( 'crontrol-clear-logs', 'crontrol_nonce' );

		if ( '' !== $current_hook ) {
			printf(
				'<input type="hidden" name="crontrol_hook" value="%s" />',
				esc_attr( $current_hook )
			);
			$label = __( 'Clear all logs for this hook', 'wp-crontrol' );
		} else {
			$label = __( 'Clear All Logs', 'wp-crontrol' );
		}

		printf(
			'<button class="button" type="submit" name="crontrol_action" value="clear-logs" data-crontrol-clear-logs>%s</button>',
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Message to be displayed when there are no items.
	 *
	 * @return void
	 */
	#[\Override]
	public function no_items() {
		if ( isset( $_GET['crontrol_pro_message'] ) && absint( $_GET['crontrol_pro_message'] ) === \Crontrol\Pro\MESSAGE_LOGS_CLEARED ) {
			echo '<p>' . esc_html__( 'All logs have been cleared successfully.', 'wp-crontrol' ) . '</p>';
		} elseif ( isset( $_GET['crontrol_hook'] ) ) {
			echo '<p>';
			printf(
				/* translators: %s: The hook name that was filtered for */
				esc_html__( 'No matching %s cron event logs found.', 'wp-crontrol' ),
				'<b>' . esc_html( sanitize_text_field( $_GET['crontrol_hook'] ) ) . '</b>',
			);
		} elseif ( isset( $_GET['crontrol_status'] ) ) {
			echo '<p>' . esc_html__( 'No matching cron event logs found.', 'wp-crontrol' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No cron event logs found yet.', 'wp-crontrol' ) . '</p>';
			echo '<p>';
			printf(
				/* translators: %s: URL of the Cron Events screen */
				wp_kses( __( 'Each run of a logged event appears here. Turn logging on for an event with its Enable logging link on the <a href="%s">Cron Events screen</a>, or in the settings below, then use Run now to see a first entry.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'tools.php?page=wp-crontrol' ) )
			);
			echo '</p>';
			echo '<p>';
			printf(
				/* translators: %s: URL of the Cron AI screen */
				wp_kses( __( 'An AI assistant can read these logs for you. <a href="%s">See Cron AI</a>.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'tools.php?page=wp-crontrol-ai' ) )
			);
			echo '</p>';
		}
	}

	/**
	 * Generate and display a single row for the table.
	 *
	 * @param EventRun $item The current item.
	 */
	#[\Override]
	public function single_row( $item ): void {
		// Get the current row number from the parent class
		static $row_number = 0;
		++$row_number;

		$column_count = count( $this->get_columns() );
		$exceeded_interval = $item->exceeded_interval();
		$approached_interval = $item->approached_interval();

		$row_classes = [];
		if ( $item->is_errored() ) {
			$row_classes[] = 'crontrol-errored';
		}

		// Add alternate class for striping (WordPress standard)
		if ( $row_number % 2 === 0 ) {
			$row_classes[] = 'alternate';
		}

		if ( ! empty( $item->errors ) ) {
			$row_classes[] = 'crontrol-error-message';
		} elseif ( $exceeded_interval ) {
			$row_classes[] = 'crontrol-error-message';
		} elseif ( $approached_interval ) {
			$row_classes[] = 'crontrol-warning-message';
		}

		$class_attr = implode( ' ', $row_classes );

		echo '<tr class="' . esc_attr( $class_attr ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';

		// Add error message row if the event has error messages
		if ( ! empty( $item->errors ) ) {
			// Each message is escaped individually before being joined with line breaks.
			$messages = implode( '<br>', array_map(
				fn ( array $error ): string => esc_html( $error['message'] ),
				$item->errors
			) );
			echo '<tr class="crontrol-supplemental-row ' . esc_attr( $class_attr ) . '">';
			echo '<td colspan="' . esc_attr( (string) $column_count ) . '">';
			echo '<span class="status-crontrol-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span></span> <b>' . $messages . '</b>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</td>';
			echo '</tr>';
		}

		// Add a row flagging when the run approached or exceeded its schedule's recurrence interval.
		if ( $exceeded_interval || $approached_interval ) {
			$interval = \Crontrol\interval( $item->interval_seconds );

			echo '<tr class="crontrol-supplemental-row ' . esc_attr( $class_attr ) . '">';
			echo '<td colspan="' . esc_attr( (string) $column_count ) . '">';

			if ( $exceeded_interval ) {
				$message = sprintf(
					/* translators: %s: Schedule recurrence interval */
					__( 'This event ran for longer than its recurrence interval of %s. It may have overlapped or delayed the next event.', 'wp-crontrol' ),
					$interval
				);
				echo '<span class="status-crontrol-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span></span> <b>' . esc_html( $message ) . '</b>';
			} else {
				$message = sprintf(
					/* translators: %s: Schedule recurrence interval */
					__( 'This event ran for over 75%% of its recurrence interval of %s. It may risk overlapping or delaying future events.', 'wp-crontrol' ),
					$interval
				);
				echo '<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span></span> <b>' . esc_html( $message ) . '</b>';
			}

			echo '</td>';
			echo '</tr>';
		}
	}
}
