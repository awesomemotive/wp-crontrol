<?php
/**
 * List table for cron events.
 */

namespace Crontrol\Event;

use DateTimeImmutable;
use stdClass;

use function Crontrol\php_cron_events_enabled;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/**
 * Cron event list table class.
 */
class Table extends \WP_List_Table {
	/**
	 * Array of cron event hooks that are persistently added by WordPress core.
	 *
	 * @var array<int,string> Array of hook names.
	 */
	protected static $persistent_core_hooks;

	/**
	 * Whether the current user has the capability to create or edit PHP cron events.
	 *
	 * @var bool Whether the user can create or edit PHP cron events.
	 */
	protected static $can_manage_php_crons;

	/**
	 * Whether PHP cron events are allowed.
	 *
	 * @var bool Whether PHP cron events are allowed.
	 */
	protected static $php_crons_enabled;

	/**
	 * Array of the count of each hook.
	 *
	 * @var array<string,int> Array of count of each hooked, keyed by hook name.
	 */
	protected static $count_by_hook;

	/**
	 * Array of all cron events.
	 *
	 * @var array<string,stdClass> Array of event objects.
	 */
	protected $all_events = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'crontrol-event',
			'plural'   => 'crontrol-events',
			'ajax'     => false,
			'screen'   => 'crontrol-events',
		) );
	}

	/**
	 * Prepares the list table items and arguments.
	 *
	 * @return void
	 */
	public function prepare_items() {
		self::$persistent_core_hooks = \Crontrol\get_persistent_core_hooks();
		self::$can_manage_php_crons  = current_user_can( 'edit_files' );
		self::$php_crons_enabled     = php_cron_events_enabled();
		self::$count_by_hook         = count_by_hook();

		$events = get();
		$this->all_events = $events;

		if ( ! empty( $_GET['s'] ) && is_string( $_GET['s'] ) ) {
			$s = sanitize_text_field( wp_unslash( $_GET['s'] ) );

			$events = array_filter(
				$events,
				function ( $event ) use ( $s ) {
					return ( false !== strpos( $event->hook, $s ) );
				}
			);
		}

		if ( ! empty( $_GET['crontrol_hooks_type'] ) && is_string( $_GET['crontrol_hooks_type'] ) ) {
			$hooks_type = sanitize_text_field( wp_unslash( $_GET['crontrol_hooks_type'] ) );
			$filtered = self::get_filtered_events( $events );

			if ( isset( $filtered[ $hooks_type ] ) ) {
				$events = $filtered[ $hooks_type ];
			}
		}

		$count    = count( $events );
		$per_page = 50;
		$offset   = ( $this->get_pagenum() - 1 ) * $per_page;

		$this->items = array_slice( $events, $offset, $per_page );

		$has_integrity_failures = (bool) array_filter( array_map( __NAMESPACE__ . '\\integrity_failed', $this->items ) );
		$has_late = (bool) array_filter( array_map( __NAMESPACE__ . '\\is_late', $this->items ) );

		if ( $has_integrity_failures && empty( $_GET['crontrol_action'] ) ) {
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div id="crontrol-integrity-failures-message" class="notice notice-error"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
						esc_html__( 'One or more of your cron events needs to be checked for integrity. These events will not run until you check and re-save them.', 'wp-crontrol' ),
						'https://wp-crontrol.com/help/check-cron-events/',
						esc_html__( 'Read what to do', 'wp-crontrol' )
					);
				}
			);
		}

		$this->set_pagination_args( array(
			'total_items' => $count,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $count / $per_page ),
		) );
	}

	/**
	 * Returns events filtered by various parameters
	 *
	 * @param array<string,stdClass> $events The list of all events.
	 * @return array<string,array<string,stdClass>> Array of filtered events keyed by filter name.
	 */
	public static function get_filtered_events( array $events ) {
		$all_core_hooks = \Crontrol\get_all_core_hooks();
		$filtered = array(
			'all' => $events,
		);

		$filtered['noaction'] = array_filter(
			$events,
			function ( $event ) {
				$hook_callbacks = \Crontrol\get_hook_callbacks( $event->hook );
				return empty( $hook_callbacks );
			}
		);

		$filtered['core'] = array_filter(
			$events,
			function ( $event ) use ( $all_core_hooks ) {
				return ( in_array( $event->hook, $all_core_hooks, true ) );
			}
		);

		$filtered['custom'] = array_filter(
			$events,
			function ( $event ) use ( $all_core_hooks ) {
				return ( ! in_array( $event->hook, $all_core_hooks, true ) );
			}
		);

		$filtered['php'] = array_filter(
			$events,
			function ( $event ) {
				return ( 'crontrol_cron_job' === $event->hook );
			}
		);

		$filtered['url'] = array_filter(
			$events,
			function ( $event ) {
				return ( 'crontrol_url_cron_job' === $event->hook );
			}
		);

		$filtered['paused'] = array_filter(
			$events,
			function ( $event ) {
				return ( is_paused( $event ) );
			}
		);

		/**
		 * Filters the available filtered events on the cron event listing screen.
		 *
		 * See the corresponding `crontrol/filter-types` filter to adjust the list of filter types.
		 *
		 * @since 1.11.0
		 *
		 * @param array[]    $filtered Array of filtered event arrays keyed by filter name.
		 * @param stdClass[] $events   Array of all events.
		 */
		$filtered = apply_filters( 'crontrol/filtered-events', $filtered, $events );

		return $filtered;
	}

	/**
	 * Returns an array of column names for the table.
	 *
	 * @return array<string,string> Array of column names keyed by their ID.
	 */
	public function get_columns() {
		return array(
			'cb'                  => '<input type="checkbox" />',
			'crontrol_hook'       => esc_html__( 'Hook', 'wp-crontrol' ),
			'crontrol_next'       => esc_html(
				sprintf(
					/* translators: %s: UTC offset */
					__( 'Next Run (%s)', 'wp-crontrol' ),
					\Crontrol\get_timezone_location()
				),
			),
			'crontrol_schedule'   => esc_html_x( 'Schedule', 'noun', 'wp-crontrol' ),
			'crontrol_actions'    => esc_html__( 'Action', 'wp-crontrol' ),
		);
	}

	/**
	 * Columns to make sortable.
	 *
	 * @return array<string,array<int,mixed>>
	 * @phpstan-return array<string,array{
	 *   0: string,
	 *   1: bool,
	 *   2?: ?string,
	 *   3?: ?string,
	 *   4?: 'asc'|'desc',
	 * }>
	 */
	public function get_sortable_columns() {
		return array(
			'crontrol_hook' => array( 'crontrol_hook', false ),
			'crontrol_next' => array( 'crontrol_next', false, null, null, 'asc' ),
			'crontrol_schedule' => array( 'crontrol_schedule', false ),
		);
	}

	/**
	 * Returns an array of CSS class names for the table.
	 *
	 * @return array<int,string> Array of class names.
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'striped', 'table-view-list', $this->_args['plural'] );
	}

	/**
	 * Get an associative array ( option_name => option_title ) with the list
	 * of bulk actions available on this table.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'crontrol_delete_crons' => esc_html__( 'Delete', 'wp-crontrol' ),
		);
	}

	/**
	 * Display the list of hook types.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		$filtered = self::get_filtered_events( $this->all_events );

		$views = array();
		$hooks_type = ( ! empty( $_GET['crontrol_hooks_type'] ) ? $_GET['crontrol_hooks_type'] : 'all' );

		$types = array(
			'all'      => __( 'All events', 'wp-crontrol' ),
			'noaction' => __( 'Events with no action', 'wp-crontrol' ),
			'core'     => __( 'WordPress core events', 'wp-crontrol' ),
			'custom'   => __( 'Custom events', 'wp-crontrol' ),
			'php'      => __( 'PHP events', 'wp-crontrol' ),
			'url'      => __( 'URL events', 'wp-crontrol' ),
			'paused'   => __( 'Paused events', 'wp-crontrol' ),
		);

		/**
		 * Filters the filter types on the cron event listing screen.
		 *
		 * See the corresponding `crontrol/filtered-events` filter to adjust the filtered events.
		 *
		 * @since 1.11.0
		 *
		 * @param string[] $types      Array of filter names keyed by filter name.
		 * @param string   $hooks_type The current filter name.
		 */
		$types = apply_filters( 'crontrol/filter-types', $types, $hooks_type );

		$url = admin_url( 'tools.php?page=wp-crontrol' );

		/**
		 * @var array<string,string> $types
		 */
		foreach ( $types as $key => $type ) {
			if ( ! isset( $filtered[ $key ] ) ) {
				continue;
			}

			$count = count( $filtered[ $key ] );

			if ( ! $count ) {
				continue;
			}

			$link = ( 'all' === $key ) ? $url : add_query_arg( 'crontrol_hooks_type', $key, $url );

			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $link ),
				$hooks_type === $key ? ' class="current"' : '',
				esc_html( $type ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which One of 'top' or 'bottom' to indicate the position on the screen.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		wp_nonce_field( 'crontrol-export-event-csv', 'crontrol_nonce' );
		printf(
			'<input type="hidden" name="crontrol_hooks_type" value="%s"/>',
			esc_attr( isset( $_GET['crontrol_hooks_type'] ) ? sanitize_text_field( wp_unslash( $_GET['crontrol_hooks_type'] ) ) : 'all' )
		);
		printf(
			'<input type="hidden" name="s" value="%s"/>',
			esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' )
		);
		printf(
			'<button class="button" type="submit" name="crontrol_action" value="export-event-csv">%s</button>',
			esc_html__( 'Export', 'wp-crontrol' )
		);
	}

	/**
	 * Generates content for a single row of the table.
	 *
	 * @param stdClass $event The current event.
	 * @return void
	 */
	public function single_row( $event ) {
		$classes = array();

		if ( ( 'crontrol_cron_job' === $event->hook ) && isset( $event->args[0]['syntax_error_message'] ) ) {
			$classes[] = 'crontrol-error';
		}

		if ( integrity_failed( $event ) ) {
			$classes[] = 'crontrol-error';
		}

		$schedule_name = ( $event->interval ? get_schedule_name( $event ) : false );

		if ( is_wp_error( $schedule_name ) ) {
			$classes[] = 'crontrol-error';
		}

		$callbacks = \Crontrol\get_hook_callbacks( $event->hook );

		if ( ! $callbacks ) {
			$classes[] = 'crontrol-no-action';
		} else {
			foreach ( $callbacks as $callback ) {
				if ( ! empty( $callback['callback']['error'] ) ) {
					$classes[] = 'crontrol-error';
					break;
				}
			}
		}

		if ( is_late( $event ) || is_too_frequent( $event ) ) {
			$classes[] = 'crontrol-warning';
		}

		if ( is_paused( $event ) ) {
			$classes[] = 'crontrol-paused';
		}

		if ( 'crontrol_cron_job' === $event->hook && ! php_cron_events_enabled() ) {
			$classes[] = 'crontrol-inactive';
		}

		printf(
			'<tr class="%s">',
			esc_attr( implode( ' ', $classes ) )
		);

		$this->single_row_columns( $event );
		echo '</tr>';
	}

	/**
	 * Generates and displays row action links for the table.
	 *
	 * @param stdClass $event       The cron event for the current row.
	 * @param string   $column_name Current column name.
	 * @param string   $primary     Primary column name.
	 * @return string The row actions HTML.
	 */
	protected function handle_row_actions( $event, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$links = array();

		if ( $event->timestamp === 1 ) {
			// This is an event that is scheduled to run immediately. These only appear when there's a problem with
			// the event runner, so the only link we need to show is the "Delete" link.
			$link = array(
				'page'                  => 'wp-crontrol',
				'crontrol_action'       => 'delete-cron',
				'crontrol_id'           => rawurlencode( $event->hook ),
				'crontrol_sig'          => rawurlencode( $event->sig ),
				'crontrol_next_run_utc' => '1',
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );
			$link = wp_nonce_url( $link, "crontrol-delete-cron_{$event->hook}_{$event->sig}_{$event->timestamp}" );

			$links[] = "<span class='delete'><a href='" . esc_url( $link ) . "'>" . esc_html__( 'Delete', 'wp-crontrol' ) . '</a></span>';

			return $this->row_actions( $links );
		}

		// PHP cron events can be edited as long as they are enabled and the user has permission.
		$can_edit = ( 'crontrol_cron_job' !== $event->hook ) || ( self::$can_manage_php_crons && self::$php_crons_enabled );

		if ( $can_edit ) {
			$link = array(
				'page'                  => 'wp-crontrol',
				'crontrol_action'       => 'edit-cron',
				'crontrol_id'           => rawurlencode( $event->hook ),
				'crontrol_sig'          => rawurlencode( $event->sig ),
				'crontrol_next_run_utc' => rawurlencode( $event->timestamp ),
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );

			if ( integrity_failed( $event ) ) {
				$label = __( 'Check and edit', 'wp-crontrol' );
			} else {
				$label = __( 'Edit', 'wp-crontrol' );
			}

			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $link ),
				esc_html( $label )
			);
		}

		// PHP cron events can be run as long as they are enabled.
		$can_run = ( 'crontrol_cron_job' !== $event->hook ) || self::$php_crons_enabled;

		if ( ! is_paused( $event ) && ! integrity_failed( $event ) && $can_run ) {
			$link = array(
				'page'                  => 'wp-crontrol',
				'crontrol_action'       => 'run-cron',
				'crontrol_id'           => rawurlencode( $event->hook ),
				'crontrol_sig'          => rawurlencode( $event->sig ),
				'crontrol_next_run_utc' => rawurlencode( $event->timestamp ),
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );
			$link = wp_nonce_url( $link, "crontrol-run-cron_{$event->hook}_{$event->sig}" );

			$links[] = "<a href='" . esc_url( $link ) . "'>" . esc_html__( 'Run now', 'wp-crontrol' ) . '</a>';
		}

		if ( is_paused( $event ) ) {
			$link = array(
				'page'            => 'wp-crontrol',
				'crontrol_action' => 'resume-hook',
				'crontrol_id'     => rawurlencode( $event->hook ),
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );
			$link = wp_nonce_url( $link, "crontrol-resume-hook_{$event->hook}" );

			/* translators: Resume is a verb */
			$links[] = "<a href='" . esc_url( $link ) . "'>" . esc_html__( 'Resume this hook', 'wp-crontrol' ) . '</a>';
		} elseif ( 'crontrol_cron_job' !== $event->hook && 'crontrol_url_cron_job' !== $event->hook ) {
			$link = array(
				'page'            => 'wp-crontrol',
				'crontrol_action' => 'pause-hook',
				'crontrol_id'     => rawurlencode( $event->hook ),
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );
			$link = wp_nonce_url( $link, "crontrol-pause-hook_{$event->hook}" );

			/* translators: Pause is a verb */
			$links[] = "<a href='" . esc_url( $link ) . "'>" . esc_html__( 'Pause this hook', 'wp-crontrol' ) . '</a>';
		}

		$links = apply_filters( 'crontrol/event-actions', $links, $event );

		// PHP cron events can be deleted even if they're disallowed, as long as the user has permission.
		if ( ! in_array( $event->hook, self::$persistent_core_hooks, true ) && ( ( 'crontrol_cron_job' !== $event->hook ) || self::$can_manage_php_crons ) ) {
			$link = array(
				'page'                  => 'wp-crontrol',
				'crontrol_action'       => 'delete-cron',
				'crontrol_id'           => rawurlencode( $event->hook ),
				'crontrol_sig'          => rawurlencode( $event->sig ),
				'crontrol_next_run_utc' => rawurlencode( $event->timestamp ),
			);
			$link = add_query_arg( $link, admin_url( 'tools.php' ) );
			$link = wp_nonce_url( $link, "crontrol-delete-cron_{$event->hook}_{$event->sig}_{$event->timestamp}" );

			$links[] = "<span class='delete'><a href='" . esc_url( $link ) . "' data-crontrol-delete-event>" . esc_html__( 'Delete', 'wp-crontrol' ) . '</a></span>';
		}

		if ( 'crontrol_cron_job' !== $event->hook && 'crontrol_url_cron_job' !== $event->hook ) {
			if ( self::$count_by_hook[ $event->hook ] > 1 ) {
				$link = array(
					'page'            => 'wp-crontrol',
					'crontrol_action' => 'delete-hook',
					'crontrol_id'     => rawurlencode( $event->hook ),
				);
				$link = add_query_arg( $link, admin_url( 'tools.php' ) );
				$link = wp_nonce_url(
					$link,
					sprintf(
						'crontrol-delete-hook_%1$s',
						$event->hook
					)
				);
				$text = sprintf(
					/* translators: %s: The number of events with this hook */
					__( 'Delete all events with this hook (%s)', 'wp-crontrol' ),
					number_format_i18n( self::$count_by_hook[ $event->hook ] )
				);

				$links[] = sprintf(
					'<span class="delete"><a href="%1$s" data-crontrol-delete-hook>%2$s</a></span>',
					esc_url( $link ),
					esc_html( $text )
				);
			}
		}

		return $this->row_actions( $links );
	}

	/**
	 * Outputs the checkbox cell of a table row.
	 *
	 * @param stdClass $event The cron event for the current row.
	 * @return string The cell output.
	 */
	protected function column_cb( $event ) {
		$id = sprintf(
			'crontrol-delete-%1$s-%2$s-%3$s',
			$event->timestamp,
			rawurlencode( $event->hook ),
			$event->sig
		);

		if ( in_array( $event->hook, self::$persistent_core_hooks, true ) ) {
			return sprintf(
				'<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
				<span class="screen-reader-text">%s</span>',
				esc_html__( 'This is a WordPress core event and cannot be deleted', 'wp-crontrol' )
			);
		} elseif ( ( 'crontrol_cron_job' !== $event->hook ) || self::$can_manage_php_crons ) {
			return sprintf(
				'<label for="%1$s"><span class="screen-reader-text">%2$s</span></label>
				<input type="checkbox" name="crontrol_delete[%3$s][%4$s]" value="%5$s" id="%1$s">',
				esc_attr( $id ),
				esc_html__( 'Select this row', 'wp-crontrol' ),
				esc_attr( $event->timestamp ),
				esc_attr( rawurlencode( $event->hook ) ),
				esc_attr( $event->sig )
			);
		}

		return '';
	}

	/**
	 * Returns the output for the hook name cell of a table row.
	 *
	 * @param stdClass $event The cron event for the current row.
	 * @return string The cell output.
	 */
	protected function column_crontrol_hook( $event ) {
		if ( 'crontrol_cron_job' === $event->hook ) {
			if ( ! empty( $event->args[0]['name'] ) ) {
				/* translators: %s: Details about the PHP cron event. */
				$output = esc_html( sprintf( __( 'PHP cron event (%s)', 'wp-crontrol' ), $event->args[0]['name'] ) );
			} elseif ( ! empty( $event->args[0]['code'] ) ) {
				$lines = explode( "\n", trim( $event->args[0]['code'] ) );
				$code  = reset( $lines );
				$code  = substr( $code, 0, 50 );

				$php = sprintf(
					'<code>%s</code>&hellip;',
					esc_html( $code )
				);

				/* translators: %s: Details about the PHP cron event. */
				$output = sprintf( esc_html__( 'PHP cron event (%s)', 'wp-crontrol' ), $php );
			} else {
				$output = esc_html__( 'PHP cron event', 'wp-crontrol' );
			}

			if ( ! php_cron_events_enabled() ) {
				$output .= sprintf(
					' &mdash; <strong class="status-crontrol-inactive post-state"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span> %s</strong>',
					/* translators: State of a cron event, adjective */
					esc_html__( 'Inactive', 'wp-crontrol' )
				);
			} elseif ( integrity_failed( $event ) ) {
				$output .= sprintf(
					' &mdash; <strong class="status-crontrol-inactive post-state"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</strong>',
					esc_html__( 'Needs checking', 'wp-crontrol' )
				);
			}

			if ( isset( $event->args[0]['syntax_error_message'], $event->args[0]['syntax_error_line'] ) ) {
				$output .= '<br><span class="status-crontrol-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
				$output .= sprintf(
					/* translators: 1: Line number, 2: Error message text */
					esc_html__( 'Line %1$s: %2$s', 'wp-crontrol' ),
					esc_html( number_format_i18n( $event->args[0]['syntax_error_line'] ) ),
					esc_html( $event->args[0]['syntax_error_message'] )
				);
				$output .= '</span>';
			}

			return $output;
		}

		if ( 'crontrol_url_cron_job' === $event->hook ) {
			if ( ! empty( $event->args[0]['name'] ) ) {
				/* translators: %s: Details about the URL cron event. */
				$output = esc_html( sprintf( __( 'URL cron event (%s)', 'wp-crontrol' ), $event->args[0]['name'] ) );
			} elseif ( ! empty( $event->args[0]['url'] ) ) {
				$url = sprintf(
					'<code>%s</code>',
					esc_html( $event->args[0]['url'] )
				);

				/* translators: %s: Details about the URL cron event. */
				$output = sprintf( esc_html__( 'URL cron event (%s)', 'wp-crontrol' ), $url );
			} else {
				$output = esc_html__( 'URL cron event', 'wp-crontrol' );
			}

			return $output;
		}

		$output = esc_html( $event->hook );

		if ( is_paused( $event ) ) {
			$output .= sprintf(
				' &mdash; <strong class="status-crontrol-inactive post-state"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span> %s</strong>',
				/* translators: State of a cron event, adjective */
				esc_html__( 'Paused', 'wp-crontrol' )
			);
		}

		if ( ! empty( $event->args ) ) {
			$output .= sprintf(
				'<br><br><pre>%s</pre>',
				esc_html( \Crontrol\json_output( $event->args ) )
			);
		}

		return $output;
	}

	/**
	 * Returns the output for the actions cell of a table row.
	 *
	 * @param stdClass $event The cron event for the current row.
	 * @return string The cell output.
	 */
	protected function column_crontrol_actions( $event ) {
		if ( 'crontrol_cron_job' === $event->hook && ! php_cron_events_enabled() ) {
			return sprintf(
				'<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</span>',
				esc_html__( 'PHP cron events are disabled.', 'wp-crontrol' )
			);
		}

		$hook_callbacks = \Crontrol\get_hook_callbacks( $event->hook );

		if ( 'crontrol_cron_job' === $event->hook || 'crontrol_url_cron_job' === $event->hook ) {
			return 'WP Crontrol';
		} elseif ( ! empty( $hook_callbacks ) ) {
			$callbacks = array();

			foreach ( $hook_callbacks as $callback ) {
				$callbacks[] = \Crontrol\output_callback( $callback );
			}

			if ( $event->hook === 'action_scheduler_run_queue' ) {
				$callbacks[] = '';
				$callbacks[] = sprintf(
					'<span class="status-crontrol-info"><span class="dashicons dashicons-info" aria-hidden="true"></span> <a href="%s">%s</a></span>',
					admin_url( 'tools.php?page=action-scheduler' ),
					esc_html__( 'View the scheduled actions here &raquo;', 'wp-crontrol' )
				);
			}

			return implode( '<br>', $callbacks ); // WPCS:: XSS ok.
		} else {
			$help = sprintf(
				'<a href="%s">%s</a>',
				'https://wp-crontrol.com/help/no-action-cron-events/',
				esc_html__( 'Help', 'wp-crontrol' )
			);
			return sprintf(
				'<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %1$s</span> (%2$s)',
				esc_html__( 'None', 'wp-crontrol' ),
				$help,
			);
		}
	}

	/**
	 * Returns the output for the next run cell of a table row.
	 *
	 * @param stdClass $event The cron event for the current row.
	 * @return string The cell output.
	 */
	protected function column_crontrol_next( $event ) {
		if ( $event->timestamp === 1 ) {
			return sprintf(
				'<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</span>',
				esc_html__( 'Immediately', 'wp-crontrol' ),
			);
		}

		$time_format = 'g:i a';

		$event_datetime_utc = gmdate( 'Y-m-d H:i:s', $event->timestamp );

		$timezone_local  = wp_timezone();
		$event_local     = get_date_from_gmt( $event_datetime_utc, 'Y-m-d' );
		$today_local     = ( new DateTimeImmutable( 'now', $timezone_local ) )->format( 'Y-m-d' );
		$tomorrow_local  = ( new DateTimeImmutable( 'tomorrow', $timezone_local ) )->format( 'Y-m-d' );
		$yesterday_local = ( new DateTimeImmutable( 'yesterday', $timezone_local ) )->format( 'Y-m-d' );

		// If the offset of the date of the event is different from the offset of the site, add a marker.
		if ( get_date_from_gmt( $event_datetime_utc, 'P' ) !== get_date_from_gmt( 'now', 'P' ) ) {
			$time_format .= ' (P)';
		}

		$event_time_local = get_date_from_gmt( $event_datetime_utc, $time_format );

		if ( $event_local === $today_local ) {
			$date = sprintf(
				/* translators: %s: Time */
				__( 'Today at %s', 'wp-crontrol' ),
				$event_time_local,
			);
		} elseif ( $event_local === $tomorrow_local ) {
			$date = sprintf(
				/* translators: %s: Time */
				__( 'Tomorrow at %s', 'wp-crontrol' ),
				$event_time_local,
			);
		} elseif ( $event_local === $yesterday_local ) {
			$date = sprintf(
				/* translators: %s: Time */
				__( 'Yesterday at %s', 'wp-crontrol' ),
				$event_time_local,
			);
		} else {
			$date = sprintf(
				/* translators: 1: Date, 2: Time */
				__( '%1$s at %2$s', 'wp-crontrol' ),
				get_date_from_gmt( $event_datetime_utc, 'F jS' ),
				$event_time_local,
			);
		}

		$time = sprintf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( gmdate( 'c', $event->timestamp ) ),
			esc_html( $date )
		);

		$until = $event->timestamp - time();
		$late  = is_late( $event );

		if ( $late ) {
			// Show a warning for events that are late.
			$ago = sprintf(
				/* translators: %s: Time period, for example "8 minutes" */
				__( '%s ago', 'wp-crontrol' ),
				\Crontrol\interval( abs( $until ) )
			);
			$help = sprintf(
				'<a href="%s">%s</a>',
				'https://wp-crontrol.com/help/missed-cron-events/',
				esc_html__( 'Help', 'wp-crontrol' )
			);
			return sprintf(
				'<span class="status-crontrol-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</span> (%s)<br>%s',
				esc_html( $ago ),
				$help,
				$time,
			);
		}

		if ( $until <= 0 ) {
			$in = __( 'Now', 'wp-crontrol' );
		} else {
			$in = sprintf(
				/* translators: %s: Time period, for example "8 minutes" */
				__( 'In %s', 'wp-crontrol' ),
				\Crontrol\interval( $until ),
			);
		}

		return sprintf(
			'%s<br>%s',
			esc_html( $in ),
			$time,
		);
	}

	/**
	 * Returns the output for the schedule cell of a table row.
	 *
	 * @param stdClass $event The cron event for the current row.
	 * @return string The cell output.
	 */
	protected function column_crontrol_schedule( $event ) {
		if ( $event->schedule ) {
			$schedule_name = get_schedule_name( $event );
			if ( is_wp_error( $schedule_name ) ) {
				return sprintf(
					'<span class="status-crontrol-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</span>',
					esc_html( $schedule_name->get_error_message() )
				);
			} elseif ( is_too_frequent( $event ) ) {
				return sprintf(
					'%1$s<span class="status-crontrol-warning"><br><span class="dashicons dashicons-warning" aria-hidden="true"></span> %2$s</span>',
					esc_html( $schedule_name ),
					sprintf(
						/* translators: 1: The name of the configuration constant, 2: The value of the configuration constant */
						esc_html__( 'This interval is less than the %1$s constant which is set to %2$s seconds. Events that use it may not run on time.', 'wp-crontrol' ),
						'<code>WP_CRON_LOCK_TIMEOUT</code>',
						intval( WP_CRON_LOCK_TIMEOUT )
					)
				);
			} else {
				return esc_html( $schedule_name );
			}
		} else {
			return esc_html__( 'Non-repeating', 'wp-crontrol' );
		}
	}

	/**
	 * Outputs a message when there are no items to show in the table.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( empty( $_GET['s'] ) && empty( $_GET['crontrol_hooks_type'] ) ) {
			esc_html_e( 'There are currently no scheduled cron events.', 'wp-crontrol' );
		} else {
			esc_html_e( 'No matching cron events.', 'wp-crontrol' );
		}
	}
}
