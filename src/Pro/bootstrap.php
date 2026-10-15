<?php
/**
 * Functions related to bootstrapping WP Crontrol Pro.
 */

namespace Crontrol\Pro;

/**
 * Message constants for admin notices.
 */
const MESSAGE_LOGS_CLEARED = 1;
const MESSAGE_CLEAR_FAILED = 2;
const MESSAGE_LOGGING_ENABLED = 3;
const MESSAGE_LOGGING_DISABLED = 4;
const MESSAGE_LOGS_CLEARED_FOR_HOOK = 5;
const MESSAGE_LOGGING_SETTINGS_SAVED = 6;

/**
 * Internal cron hooks used by WP Crontrol Pro itself.
 *
 * @var array<int,string>
 */
const INTERNAL_HOOKS = array(
	'crontrol/metrics',
	'crontrol/maintenance',
	'crontrol/alerts_digest',
);

/**
 * Number of days that cron event log entries are kept.
 */
const LOG_RETENTION_DAYS = 30;

/**
 * Number of hours that the metrics job waits for runs that are still going before it aggregates
 * the hour they started in anyway.
 */
const METRICS_RUNNING_GRACE_HOURS = 3;

/**
 * Number of days that aggregated metrics are kept.
 */
const METRIC_RETENTION_DAYS = 90;

/**
 * Hook onto all of the actions and filters needed for cron event logging.
 */
function init_hooks(): void {
	add_action( 'admin_menu',                                               __NAMESPACE__ . '\action_admin_menu', 99 );
	add_action( 'admin_init',                                               __NAMESPACE__ . '\action_admin_init' );
	add_action( 'admin_notices',                                            __NAMESPACE__ . '\action_admin_notices' );
	add_action( 'admin_enqueue_scripts',                                    __NAMESPACE__ . '\action_admin_enqueue_scripts' );
	add_filter( 'removable_query_args',                                     __NAMESPACE__ . '\filter_removable_query_args' );
	add_filter( 'crontrol/event_admin_messages',                            __NAMESPACE__ . '\filter_event_admin_messages', 10, 3 );
	add_filter( 'crontrol/event-actions',                                   __NAMESPACE__ . '\filter_event_actions', 10, 2 );
	add_filter( 'crontrol/event_table_columns',                             __NAMESPACE__ . '\filter_event_table_columns' );
	add_filter( 'crontrol/event_table_column_output_crontrol_pro_last_run', __NAMESPACE__ . '\filter_event_table_last_run_column', 10, 2 );
	add_action( 'crontrol/manage/hookname',                                 __NAMESPACE__ . '\render_logging_field' );
	add_action( 'crontrol/added_new_event',                                 __NAMESPACE__ . '\handle_new_event_logging' );
	add_action( 'init',                                                     __NAMESPACE__ . '\on_init' );
	add_action( 'crontrol/metrics',                                         __NAMESPACE__ . '\metrics' );
	add_action( 'crontrol/maintenance',                                     __NAMESPACE__ . '\maintenance' );

	add_filter( 'crontrol/links', function ( array $links ): array {
		// The Insights tab sits alongside Cron Logs.
		$insights = array(
			'insights' => array(
				'tools.php?page=wp-crontrol-insights',
				__( 'Cron Insights', 'wp-crontrol' ),
				true,
			),
		);

		$position = array_search( 'logs', array_keys( $links ), true );

		if ( false === $position ) {
			$links = array_merge( $links, $insights );
		} else {
			$links = array_merge(
				array_slice( $links, 0, $position + 1, true ),
				$insights,
				array_slice( $links, $position + 1, null, true )
			);
		}

		if ( tables_ready() && Log::has_errors() ) {
			$links['logs'][0] = add_query_arg( 'crontrol_status', 'errored', $links['logs'][0] );
			$links['logs'][3] = true;
		}

		return $links;
	} );

	add_filter( 'crontrol/tabs', function ( array $tabs ): array {
		$tabs['insights'] = ( isset( $_GET['page'] ) && 'wp-crontrol-insights' === $_GET['page'] );
		return $tabs;
	} );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'crontrol logs', __NAMESPACE__ . '\CLI\LogsCommand' );
	}
}

/**
 * Starts logging when it's enabled for at least one hook.
 *
 * Logging is off by default. Nothing here touches the database on a normal page load
 * unless logging has been turned on.
 */
function on_init(): void {
	$maintenance_context = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

	// Front-end requests never run cron events, so they don't need to read any logging settings.
	if ( ! $maintenance_context && ! Log::is_cron_context() ) {
		// Except with ALTERNATE_WP_CRON, where wp-cron.php runs later in this request.
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			add_filter( 'pre_get_ready_cron_jobs', __NAMESPACE__ . '\start_alternate_cron_logging' );
		}

		return;
	}

	$logging = (bool) Log::get_enabled_hooks();

	// Jobs are only needed once something is logged; a site that never logs reads no log settings.
	if ( $maintenance_context && $logging ) {
		maybe_schedule_jobs();
	}

	if ( ! $logging ) {
		return;
	}

	if ( ! tables_ready() ) {
		if ( ! $maintenance_context || ! init_db() ) {
			return;
		}
	}

	Log::get_instance()->init();
}

/**
 * Starts logging once wp-cron.php runs inside a front-end request, as it does with ALTERNATE_WP_CRON.
 *
 * WordPress also asks for the ready cron jobs before it decides to run cron, so this waits until
 * the request is actually running cron.
 *
 * @param mixed $pre The value of the pre_get_ready_cron_jobs filter, returned unchanged.
 * @return mixed
 */
function start_alternate_cron_logging( $pre ) {
	if ( wp_doing_cron() ) {
		remove_filter( 'pre_get_ready_cron_jobs', __NAMESPACE__ . '\start_alternate_cron_logging' );
		on_init();
	}

	return $pre;
}

/**
 * Schedules the metrics and maintenance events once the log tables exist.
 *
 * They're scheduled on the main site and on every site that logs events, so they still run when
 * WP Crontrol is only active on some sites of a network. The tables are shared by every site, and
 * both jobs work network-wide and are safe to run more than once: metrics skip hours that are
 * already aggregated, and pruning only removes what's over the limits.
 */
function maybe_schedule_jobs(): void {
	if ( ! tables_ready() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'crontrol/metrics' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'crontrol/metrics' );
	}

	if ( ! wp_next_scheduled( 'crontrol/maintenance' ) ) {
		wp_schedule_event( time() + 90, 'daily', 'crontrol/maintenance' );
	}
}

function metrics(): void {
	global $wpdb;

	if ( ! tables_ready() ) {
		return;
	}

	// The job can run from more than one site in a network. A database lock stops two runs
	// aggregating the same hour at the same time.
	// Lock names are server-wide, so the name includes this install's database and table prefix.
	$lock = 'crontrol_metrics_' . md5( DB_NAME . '|' . $wpdb->base_prefix );

	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		return;
	}

	try {
		aggregate_missing_metrics( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/**
 * Aggregates every completed hour and day since the last aggregation, so hours that cron
 * missed are filled in later. At most two days of hours and a week of days per run.
 *
 * Runs are bucketed by the time they started. An hour or day isn't aggregated while a run that
 * started in it is still going, so that run's result and duration are counted once it finishes.
 * After METRICS_RUNNING_GRACE_HOURS it's aggregated anyway, so a run that never finished can't
 * hold the metrics back. Aggregated buckets are never recomputed.
 *
 * @param \DateTimeImmutable $now The current time in UTC.
 */
function aggregate_missing_metrics( \DateTimeImmutable $now ): void {
	$current_hour = $now->setTime( (int) $now->format( 'G' ), 0, 0 );
	$grace_start  = $current_hour->modify( '-' . METRICS_RUNNING_GRACE_HOURS . ' hours' );
	$last_hour    = get_site_option( 'crontrol_metrics_last_hour', '' );

	if ( ! is_string( $last_hour ) || '' === $last_hour ) {
		// Record the starting point before the first attempt, so a failure is retried later.
		$last_hour = $current_hour->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
		update_site_option( 'crontrol_metrics_last_hour', $last_hour );
	}

	$hour = ( new \DateTimeImmutable( $last_hour, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 hour' );

	if ( $hour < $current_hour->modify( '-48 hours' ) ) {
		$hour = $current_hour->modify( '-48 hours' );
	}

	while ( $hour < $current_hour ) {
		// Wait for runs that started in this hour to finish, within the grace period.
		if ( $hour >= $grace_start && EventRunModel::has_running( $hour, $hour->modify( '+1 hour' ) ) ) {
			break;
		}

		$results = array(
			EventMetricModel::aggregate_errors_hourly( $hour ),
			EventMetricModel::aggregate_warnings_hourly( $hour ),
			EventMetricModel::aggregate_hook_duration_hourly( $hour ),
		);

		// Stop at a failed write so the hour is retried next time. Hours that were written are
		// skipped on the retry, because each aggregation checks for existing rows first.
		if ( in_array( null, $results, true ) ) {
			return;
		}

		update_site_option( 'crontrol_metrics_last_hour', $hour->format( 'Y-m-d H:i:s' ) );
		$hour = $hour->modify( '+1 hour' );
	}

	$today    = $now->setTime( 0, 0, 0 );
	$last_day = get_site_option( 'crontrol_metrics_last_day', '' );

	if ( ! is_string( $last_day ) || '' === $last_day ) {
		$last_day = $today->modify( '-2 days' )->format( 'Y-m-d H:i:s' );
		update_site_option( 'crontrol_metrics_last_day', $last_day );
	}

	$day = ( new \DateTimeImmutable( $last_day, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' );

	if ( $day < $today->modify( '-7 days' ) ) {
		$day = $today->modify( '-7 days' );
	}

	while ( $day < $today ) {
		// A run that started before midnight may still be going; wait for it, within the grace period.
		if ( $day->modify( '+1 day' ) > $grace_start && EventRunModel::has_running( $day, $day->modify( '+1 day' ) ) ) {
			break;
		}

		if ( null === EventMetricModel::aggregate_hook_duration_daily( $day ) ) {
			return;
		}

		update_site_option( 'crontrol_metrics_last_day', $day->format( 'Y-m-d H:i:s' ) );
		$day = $day->modify( '+1 day' );
	}
}

/**
 * Trims the logs for every site by age and by row count, trims old metrics, and records
 * the hooks that are scheduled so the logging settings can list them.
 */
function maintenance(): void {
	Log::record_known_hooks();

	if ( ! tables_ready() ) {
		return;
	}

	/**
	 * Filters the number of days that cron event log entries are kept.
	 *
	 * @param int $days Number of days.
	 */
	$log_days = max( 1, (int) apply_filters( 'crontrol/log_retention_days', LOG_RETENTION_DAYS ) );

	/**
	 * Filters the number of days that aggregated cron metrics are kept.
	 *
	 * @param int $days Number of days.
	 */
	$metric_days = max( 7, (int) apply_filters( 'crontrol/metric_retention_days', METRIC_RETENTION_DAYS ) );

	EventRunModel::prune_sites( $log_days );
	EventMetricModel::prune_older_than( $metric_days );
}

/**
 * Creates or updates the log tables. Returns whether they're ready to use.
 */
function init_db(): bool {
	return EventRunModel::init_db() && EventMetricModel::init_db();
}

/**
 * Whether the log tables exist at their current version.
 */
function tables_ready(): bool {
	return EventRunModel::is_ready() && EventMetricModel::is_ready();
}

/**
 * Adds the Cron Insights page to the admin menu. The Cron Logs page is added by WP Crontrol's own menu code.
 *
 * Run using the 'admin_menu' action.
 */
function action_admin_menu(): void {
	add_management_page(
		esc_html__( 'Cron Insights', 'wp-crontrol' ),
		esc_html__( 'Cron Insights', 'wp-crontrol' ),
		'manage_options',
		'wp-crontrol-insights',
		__NAMESPACE__ . '\admin_insights_page'
	);
}

/**
 * Handle admin actions for the Cron Logs page.
 *
 * Run using the 'admin_init' action.
 */
function action_admin_init(): void {
	// Handle clear logs action
	if ( isset( $_POST['crontrol_action'] ) && 'clear-logs' === $_POST['crontrol_action'] ) {
		// Check we're on the right page
		if ( ! isset( $_GET['page'] ) || 'wp-crontrol-logs' !== $_GET['page'] ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-crontrol' ) );
		}

		// Verify nonce
		check_admin_referer( 'crontrol-clear-logs', 'crontrol_nonce' );

		// A hook filter means we only clear that hook's logs.
		$hook = ( isset( $_POST['crontrol_hook'] ) && is_string( $_POST['crontrol_hook'] ) )
			? sanitize_text_field( wp_unslash( $_POST['crontrol_hook'] ) )
			: '';

		$redirect = [
			'page' => 'wp-crontrol-logs',
		];

		if ( '' !== $hook ) {
			// Clear the logs for this hook only (only for current site in multisite).
			$success = EventRunModel::clear_records_for_hook( $hook );
			$redirect['crontrol_pro_message'] = $success ? MESSAGE_LOGS_CLEARED_FOR_HOOK : MESSAGE_CLEAR_FAILED;
			$redirect['crontrol_pro_hook'] = rawurlencode( $hook );
		} else {
			// Clear all logs (only for current site in multisite).
			$success = EventRunModel::clear_records();
			$redirect['crontrol_pro_message'] = $success ? MESSAGE_LOGS_CLEARED : MESSAGE_CLEAR_FAILED;
		}

		// Redirect to avoid form resubmission
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'tools.php' ) ) );
		exit;
	}

	// Handle saving the logging settings from the Cron Logs page.
	if ( isset( $_POST['crontrol_action'] ) && 'save-logging-settings' === $_POST['crontrol_action'] ) {
		// Check we're on the right page.
		if ( ! isset( $_GET['page'] ) || 'wp-crontrol-logs' !== $_GET['page'] ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-crontrol' ) );
		}

		// Verify nonce.
		check_admin_referer( 'crontrol-save-logging-settings', 'crontrol_nonce' );

		$hooks = ( isset( $_POST['crontrol_logged_hooks'] ) && is_array( $_POST['crontrol_logged_hooks'] ) )
			? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['crontrol_logged_hooks'] ) )
			: array();

		if ( ! empty( $_POST['crontrol_log_plugin_hooks'] ) ) {
			$hooks = array_merge( $hooks, Log::get_plugin_hooks() );
		}

		if ( $hooks ) {
			init_db();
		}

		Log::set_logged_hooks( $hooks );

		wp_safe_redirect( add_query_arg( [
			'page' => 'wp-crontrol-logs',
			'crontrol_pro_message' => MESSAGE_LOGGING_SETTINGS_SAVED,
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	// Handle the toggle logging action from the cron event list table
	if ( isset( $_GET['crontrol_action'] ) && 'crontrol-pro-toggle-logging' === $_GET['crontrol_action'] ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-crontrol' ), 403 );
		}

		$hook = ( isset( $_GET['crontrol_id'] ) && is_string( $_GET['crontrol_id'] ) )
			? (string) wp_unslash( $_GET['crontrol_id'] )
			: '';

		check_admin_referer( "crontrol-pro-toggle-logging_{$hook}" );

		// Toggle the current logging status for this hook.
		$enable = ! Log::is_hook_logged( $hook );

		if ( $enable ) {
			init_db();
		}

		Log::set_logging_for_hook( $hook, $enable );

		wp_safe_redirect( add_query_arg( [
			'page' => 'wp-crontrol',
			'crontrol_pro_message' => $enable ? MESSAGE_LOGGING_ENABLED : MESSAGE_LOGGING_DISABLED,
			'crontrol_pro_hook' => rawurlencode( $hook ),
		], admin_url( 'tools.php' ) ) );
		exit;
	}
}

/**
 * Displays an admin notice on the cron event list table after toggling logging for a hook.
 *
 * Run using the 'admin_notices' action.
 */
function action_admin_notices(): void {
	if ( ! isset( $_GET['page'] ) || 'wp-crontrol' !== $_GET['page'] ) {
		return;
	}

	if ( ! isset( $_GET['crontrol_pro_message'] ) ) {
		return;
	}

	$message_id = absint( $_GET['crontrol_pro_message'] );
	$hook = ( isset( $_GET['crontrol_pro_hook'] ) && is_string( $_GET['crontrol_pro_hook'] ) )
		? sanitize_text_field( wp_unslash( $_GET['crontrol_pro_hook'] ) )
		: '';

	switch ( $message_id ) {
		case MESSAGE_LOGGING_ENABLED:
			$message = sprintf(
				/* translators: %s: The name of a cron event hook. */
				__( 'Logging has been enabled for the %s hook.', 'wp-crontrol' ),
				'<b>' . esc_html( $hook ) . '</b>'
			);
			break;
		case MESSAGE_LOGGING_DISABLED:
			$message = sprintf(
				/* translators: %s: The name of a cron event hook. */
				__( 'Logging has been disabled for the %s hook.', 'wp-crontrol' ),
				'<b>' . esc_html( $hook ) . '</b>'
			);
			break;
		default:
			return;
	}

	wp_admin_notice( $message, array(
		'type' => 'success',
		'dismissible' => true,
	) );
}

/**
 * Enqueue admin styles and scripts for the pro plugin.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function action_admin_enqueue_scripts( string $hook_suffix ): void {
	// Load the insights visualization assets on the Cron Insights page.
	if ( $hook_suffix === 'tools_page_wp-crontrol-insights' ) {
		if ( ! tables_ready() ) {
			return;
		}

		wp_enqueue_style(
			'wp-crontrol-insights',
			plugin_dir_url( \Crontrol\PLUGIN_FILE ) . 'css/cron-insights.css',
			[],
			\Crontrol\WP_CRONTROL_VERSION
		);

		wp_enqueue_script(
			'chartjs',
			plugin_dir_url( \Crontrol\PLUGIN_FILE ) . 'js/chart.umd.min.js',
			[],
			'4.4.0',
			true
		);

		wp_enqueue_script(
			'wp-crontrol-insights',
			plugin_dir_url( \Crontrol\PLUGIN_FILE ) . 'js/cron-insights.js',
			[ 'chartjs' ],
			\Crontrol\WP_CRONTROL_VERSION,
			true
		);

		wp_localize_script( 'wp-crontrol-insights', 'wpCrontrolInsights', array(
			'data'     => Insights::get(),
			'metrics'  => MetricsInsights::get(),
			'i18n'     => array(
				'now'          => __( 'Now', 'wp-crontrol' ),
				'total_label'  => __( 'Total', 'wp-crontrol' ),
				'busiestHour'  => __( 'Busiest hour of the week', 'wp-crontrol' ),
				'selectedHour' => __( 'Selected hour', 'wp-crontrol' ),
				'errors'       => __( 'Errors', 'wp-crontrol' ),
				'baselineAvg'  => __( 'Baseline average', 'wp-crontrol' ),
				'recentAvg'    => __( 'Recent average', 'wp-crontrol' ),
				'milliseconds' => __( 'ms', 'wp-crontrol' ),
				'noData'       => __( 'No metrics have been recorded yet.', 'wp-crontrol' ),
				'noAnomalies'  => __( 'No hook slowdowns detected in the available history.', 'wp-crontrol' ),
			),
		) );

		return;
	}

	// Only load on cron logs page
	if ( $hook_suffix !== 'tools_page_wp-crontrol-logs' ) {
		return;
	}

	wp_enqueue_style(
		'wp-crontrol-logs',
		plugin_dir_url( \Crontrol\PLUGIN_FILE ) . 'css/wp-crontrol-logs.css',
		[],
		\Crontrol\WP_CRONTROL_VERSION
	);

	wp_enqueue_script(
		'wp-crontrol-logs',
		plugin_dir_url( \Crontrol\PLUGIN_FILE ) . 'js/wp-crontrol-logs.js',
		[],
		\Crontrol\WP_CRONTROL_VERSION,
		true
	);

	wp_localize_script( 'wp-crontrol-logs', 'wpCrontrolPro', array(
		'confirmClearLogs' => __( 'Are you sure you want to clear all logs? This action cannot be undone.', 'wp-crontrol' ),
		/* translators: %s: The name of a cron event hook. */
		'confirmClearLogsForHook' => __( 'Are you sure you want to clear all logs for the %s hook? This action cannot be undone.', 'wp-crontrol' ),
	) );
}

/**
 * Displays the Cron Logs page for the plugin.
 */
function admin_logs_page(): void {
	\Crontrol\dismiss_pointer( 'wp-crontrol-logs' );
	Log::record_known_hooks();

	if ( ! tables_ready() ) {
		?>
		<div class="wrap">
			<?php \Crontrol\do_tabs(); ?>

			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cron Logs', 'wp-crontrol' ); ?></h1>

			<hr class="wp-header-end">

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Logging is off. Turn it on for the cron events you want to watch, either below or with the "Enable logging" link on the Cron Events screen. Each run of a logged event is recorded here, with its duration, errors, HTTP requests, emails sent, and database queries.', 'wp-crontrol' ); ?></p>
			</div>

			<?php logging_settings_panel(); ?>
		</div>
		<?php
		return;
	}

	$table = new Logs\Table();
	$table->prepare_items();

	?>
	<div class="wrap">
		<?php \Crontrol\do_tabs(); ?>

		<h1 class="wp-heading-inline">
			<?php esc_html_e( 'Cron Logs', 'wp-crontrol' ); ?>
		</h1>

		<hr class="wp-header-end">

		<?php
		if ( isset( $_GET['crontrol_pro_message'] ) ) {
			$message_id = absint( $_GET['crontrol_pro_message'] );
			$message = '';
			$notice_class = 'notice-success';

			switch ( $message_id ) {
				case MESSAGE_LOGS_CLEARED:
					$message = __( 'All logs have been cleared successfully.', 'wp-crontrol' );
					break;
				case MESSAGE_LOGS_CLEARED_FOR_HOOK:
					$hook = ( isset( $_GET['crontrol_pro_hook'] ) && is_string( $_GET['crontrol_pro_hook'] ) )
						? sanitize_text_field( wp_unslash( $_GET['crontrol_pro_hook'] ) )
						: '';
					$message = sprintf(
						/* translators: %s: The name of a cron event hook. */
						__( 'Logs for the %s hook have been cleared successfully.', 'wp-crontrol' ),
						$hook
					);
					break;
				case MESSAGE_CLEAR_FAILED:
					$message = __( 'Failed to clear logs. Please try again.', 'wp-crontrol' );
					$notice_class = 'notice-error';
					break;
				case MESSAGE_LOGGING_SETTINGS_SAVED:
					$message = __( 'Logging settings saved successfully.', 'wp-crontrol' );
					break;
			}

			if ( ! empty( $message ) ) :
				?>
				<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
				<?php
			endif;
		}
		?>

		<?php $table->views(); ?>

		<div class="table-responsive">
			<?php $table->display(); ?>
		</div>

		<?php logging_settings_panel(); ?>
	</div>
	<?php
}

/**
 * Renders the logging settings panel beneath the Cron Logs table.
 *
 * Lists every known cron event hook with a checkbox indicating whether logging is enabled for it.
 * Submitting the form replaces the entire list of logged hooks via {@see Log::set_logged_hooks()}.
 */
function logging_settings_panel(): void {
	$hooks = Log::get_known_hooks();
	$schedules = Log::get_hook_schedules();
	foreach ( INTERNAL_HOOKS as $crontrol_hook ) {
		unset( $hooks[ $crontrol_hook ] );
	}

	// Group the hooks into custom hooks and WordPress core hooks, matching the logs filter dropdown.
	$core_hooks = \Crontrol\get_all_core_hooks();
	$custom = array_filter( $hooks, static fn ( string $hook ): bool => ! in_array( $hook, $core_hooks, true ), ARRAY_FILTER_USE_KEY );
	$core = array_filter( $hooks, static fn ( string $hook ): bool => in_array( $hook, $core_hooks, true ), ARRAY_FILTER_USE_KEY );

	?>
	<div class="crontrol-logging-settings">
		<h2><?php esc_html_e( 'Log Settings', 'wp-crontrol' ); ?></h2>
		<p><?php esc_html_e( 'Select which cron event hooks should have their execution logged.', 'wp-crontrol' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'crontrol-save-logging-settings', 'crontrol_nonce' ); ?>
			<input type="hidden" name="crontrol_action" value="save-logging-settings" />

			<?php if ( empty( $hooks ) ) : ?>
				<p><?php esc_html_e( 'No cron event hooks were found.', 'wp-crontrol' ); ?></p>
			<?php else : ?>
				<p>
					<label>
						<input type="checkbox" data-crontrol-select-all <?php checked( ! in_array( false, $hooks, true ) ); ?> />
						<strong><?php esc_html_e( 'Select all', 'wp-crontrol' ); ?></strong>
					</label>
				</p>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Hooks to log', 'wp-crontrol' ); ?></legend>
					<?php
					foreach ( $custom as $hook => $logged ) {
						logging_hook_checkbox( $hook, $logged, $schedules );
					}

					if ( $custom && $core ) {
						echo '<hr class="crontrol-logging-divider" />';
					}

					foreach ( $core as $hook => $logged ) {
						logging_hook_checkbox( $hook, $logged, $schedules );
					}
					?>
				</fieldset>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Logging Settings', 'wp-crontrol' ); ?></button>
					<button type="submit" class="button" name="crontrol_log_plugin_hooks" value="1"><?php esc_html_e( 'Log All Plugin Hooks', 'wp-crontrol' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Logging is off by default. "Log All Plugin Hooks" turns it on for the scheduled events added by plugins and themes, leaving out WordPress core events and events that run every minute.', 'wp-crontrol' ); ?></p>
			<?php endif; ?>
		</form>
	</div>
	<div class="crontrol-logging-settings">
		<h2><?php esc_html_e( 'Log Retention', 'wp-crontrol' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: Number of days, 2: Number of log entries */
				esc_html__( 'Log entries are kept for %1$s days, up to %2$s entries per site. A daily cron event removes older entries.', 'wp-crontrol' ),
				esc_html( number_format_i18n( (int) apply_filters( 'crontrol/log_retention_days', LOG_RETENTION_DAYS ) ) ),
				esc_html( number_format_i18n( EventRunModel::SOFT_LIMIT ) )
			);
			?>
		</p>
	</div>

	<?php
}

/**
 * Renders a single hook checkbox within the logging settings panel.
 *
 * @param string               $hook      The hook name.
 * @param bool                 $logged    Whether logging is currently enabled for the hook.
 * @param array<string,string> $schedules Schedule labels keyed by hook name.
 */
function logging_hook_checkbox( string $hook, bool $logged, array $schedules ): void {
	?>
	<label>
		<input
			type="checkbox"
			name="crontrol_logged_hooks[]"
			value="<?php echo esc_attr( $hook ); ?>"
			<?php checked( $logged ); ?>
		/>
		<span class="crontrol-logging-hook-name"><?php echo esc_html( $hook ); ?></span>
		<?php if ( isset( $schedules[ $hook ] ) ) : ?>
			<span class="crontrol-logging-hook-schedule"><?php echo esc_html( $schedules[ $hook ] ); ?></span>
		<?php endif; ?>
	</label>
	<?php
}

/**
 * Displays the Cron Insights page for the plugin.
 *
 * Renders the containers for the cron event density visualization. The data and charts are
 * built client-side from the localized `wpCrontrolInsights` object (see {@see get_insights_data()}).
 */
function admin_insights_page(): void {
	if ( ! tables_ready() ) {
		?>
		<div class="wrap">
			<?php \Crontrol\do_tabs(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cron Insights', 'wp-crontrol' ); ?></h1>
			<hr class="wp-header-end">
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: URL of the Cron Logs screen */
						wp_kses( __( 'Insights are built from cron event logs. <a href="%s">Turn on logging</a> to start collecting them.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'tools.php?page=wp-crontrol-logs' ) )
					);
					?>
				</p>
			</div>
		</div>
		<?php
		return;
	}

	$totals = MetricsInsights::get()['totals'];

	?>
	<div class="wrap">
		<?php \Crontrol\do_tabs(); ?>

		<h1 class="wp-heading-inline"><?php esc_html_e( 'Cron Insights', 'wp-crontrol' ); ?></h1>

		<hr class="wp-header-end">

		<div class="crontrol-insights">
			<div class="chart-container">
				<div class="chart-title"><?php esc_html_e( 'Upcoming weekly view', 'wp-crontrol' ); ?></div>
				<div id="crontrol-insights-weekly-legend" class="crontrol-insights-legend"></div>
				<canvas id="crontrol-insights-weekly"></canvas>
			</div>

			<div class="chart-container">
				<div class="chart-title" id="crontrol-insights-minutely-title"><?php esc_html_e( 'Minutely View', 'wp-crontrol' ); ?></div>
				<div id="crontrol-insights-minutely-legend" class="crontrol-insights-legend"></div>
				<canvas id="crontrol-insights-minutely"></canvas>
			</div>

			<h2 class="crontrol-insights-section-title"><?php esc_html_e( 'Historical metrics', 'wp-crontrol' ); ?></h2>

			<div class="crontrol-insights-stats" id="crontrol-insights-stats">
				<div class="crontrol-insights-stat is-error">
					<div class="crontrol-insights-stat-value">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: The number of errors. */
								_n( '%s error in the last 7 days', '%s errors in the last 7 days', $totals['errors'], 'wp-crontrol' ),
								number_format_i18n( $totals['errors'] ),
							)
						);
						?>
					</div>
					<?php
					printf(
						'<a class="button" href="%s">%s</a>',
						esc_url( add_query_arg( [
							'page' => 'wp-crontrol-logs',
							'crontrol_status' => 'errored',
						], admin_url( 'tools.php' ) ) ),
						esc_html__( 'View error logs', 'wp-crontrol' )
					);
					?>
				</div>
			</div>

			<div class="chart-container">
				<div class="chart-title"><?php esc_html_e( 'Errors (last 7 days)', 'wp-crontrol' ); ?></div>
				<canvas id="crontrol-insights-errors"></canvas>
			</div>

			<!-- <div class="chart-container">
				<div class="chart-title"><?php esc_html_e( 'Slowest hooks vs. baseline', 'wp-crontrol' ); ?></div>
				<p id="crontrol-insights-slow-empty" class="crontrol-insights-empty hidden"></p>
				<canvas id="crontrol-insights-slow"></canvas>
			</div> -->
		</div>
	</div>
	<?php
}

/**
 * Filters the list of query arguments which get removed from admin area URLs in WordPress.
 *
 * @param array<int,string> $args List of removable query arguments.
 * @return array<int,string> Updated list of removable query arguments.
 */
function filter_removable_query_args( array $args ) {
	return array_merge( $args, array(
		'crontrol_pro_message',
		'crontrol_pro_hook',
	) );
}

/**
 * Filters the admin messages shown after managing a cron event.
 *
 * @param array<int,array<int,string>> $messages Array of messages keyed by message ID.
 * @param string                       $hook     The hook name for the event being managed.
 * @param int                          $message  The message ID.
 * @return array<int,array<int,string>> Updated array of messages.
 */
function filter_event_admin_messages( array $messages, string $hook, int $message ): array {
	if ( Log::is_hook_logged( $hook ) ) {
		// Logging is enabled, so link to this hook's logs.
		$url = add_query_arg( [
			'page' => 'wp-crontrol-logs',
			'crontrol_hook' => rawurlencode( $hook ),
		], admin_url( 'tools.php' ) );

		// The message is passed through sprintf() again later, so percent signs in the URL are escaped.
		$messages[ \Crontrol\MESSAGE_EVENT_RUN_NOW ][0] .= str_replace( '%', '%%', sprintf(
			'<br><br><a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'View logs', 'wp-crontrol' ),
		) );
	} elseif ( ! in_array( $hook, INTERNAL_HOOKS, true ) ) {
		// Logging is disabled, so offer to enable it for this hook.
		$url = add_query_arg( [
			'page' => 'wp-crontrol',
			'crontrol_action' => 'crontrol-pro-toggle-logging',
			'crontrol_id' => rawurlencode( $hook ),
		], admin_url( 'tools.php' ) );
		$url = wp_nonce_url( $url, "crontrol-pro-toggle-logging_{$hook}" );

		$messages[ \Crontrol\MESSAGE_EVENT_RUN_NOW ][0] .= str_replace( '%', '%%', sprintf(
			'<br><br>%s <a href="%s">%s</a>',
			esc_html__( 'Logging is not enabled for this hook.', 'wp-crontrol' ),
			esc_url( $url ),
			esc_html__( 'Enable logging', 'wp-crontrol' ),
		) );
	}

	return $messages;
}

/**
 * Adds a row action to each event on the cron event list table that toggles whether
 * runs of that hook are logged.
 *
 * @param array<string,string>   $links Array of action links keyed by action name.
 * @param \Crontrol\Event\Event  $event The cron event for the current row.
 * @return array<string,string> Updated array of action links.
 */
function filter_event_actions( array $links, \Crontrol\Event\Event $event ): array {
	if ( in_array( $event->hook, INTERNAL_HOOKS, true ) ) {
		return $links;
	}

	$logged = Log::is_hook_logged( $event->hook );

	$url = add_query_arg( [
		'page' => 'wp-crontrol',
		'crontrol_action' => 'crontrol-pro-toggle-logging',
		'crontrol_id' => rawurlencode( $event->hook ),
	], admin_url( 'tools.php' ) );
	$url = wp_nonce_url( $url, "crontrol-pro-toggle-logging_{$event->hook}" );

	$links['crontrol-pro-logging'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $url ),
		$logged
			? esc_html__( 'Disable logging', 'wp-crontrol' )
			: esc_html__( 'Enable logging', 'wp-crontrol' )
	);

	return $links;
}

/**
 * Renders an "Enable logging of this hook" checkbox after the hook name field when
 * adding a new standard cron event.
 *
 * Hooked to the `crontrol/manage/hookname` action, which only fires for standard
 * events, so PHP and URL cron events are excluded.
 *
 * @param \Crontrol\Event\Event $existing The event being managed.
 */
function render_logging_field( \Crontrol\Event\Event $existing ): void {
	// Only offer this on the add-new form, where the hook name is not yet set.
	if ( '' !== $existing->hook ) {
		return;
	}

	if ( in_array( $existing->hook, INTERNAL_HOOKS, true ) ) {
		return;
	}

	printf(
		'<p><label><input type="checkbox" name="crontrol_pro_enable_logging" value="1" checked> %s</label></p>',
		esc_html__( 'Enable logging of this hook', 'wp-crontrol' )
	);
}

/**
 * Enables logging for a newly added cron event's hook if the "Enable logging" checkbox
 * was ticked on the add event form.
 *
 * Hooked to the `crontrol/added_new_event` action, which only fires for standard events.
 * The form submission's nonce has already been verified by WP Crontrol before this fires.
 *
 * @param object $event The newly added event's data, including its `hook` name.
 */
function handle_new_event_logging( object $event ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WP Crontrol's form handler.
	if ( empty( $_POST['crontrol_pro_enable_logging'] ) ) {
		return;
	}

	if ( ! isset( $event->hook ) || ! is_string( $event->hook ) ) {
		return;
	}

	if ( in_array( $event->hook, INTERNAL_HOOKS, true ) ) {
		return;
	}

	init_db();
	Log::set_logging_for_hook( $event->hook, true );
}

/**
 * Injects a "Last Run" column after the "Hook" column on the cron event list table.
 *
 * @param array<string,string> $columns Array of column names keyed by their ID.
 * @return array<string,string> Updated array of columns.
 */
function filter_event_table_columns( array $columns ): array {
	if ( ! tables_ready() ) {
		return $columns;
	}

	$new = [];

	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;

		if ( 'crontrol_hook' === $key ) {
			$new['crontrol_pro_last_run'] = __( 'Last Run', 'wp-crontrol' );
		}
	}

	return $new;
}

/**
 * Outputs the "Last Run" column for an event, showing the absolute and relative time
 * that an event with this hook name was most recently logged.
 *
 * @param string                $output The column output.
 * @param \Crontrol\Event\Event $event  The cron event for the current row.
 * @return string The column output.
 */
function filter_event_table_last_run_column( string $output, \Crontrol\Event\Event $event ): string {
	// Fetch every hook's last run once per request, then reuse it for each row.
	static $last_runs = null;

	if ( null === $last_runs ) {
		$last_runs = EventRunModel::get_last_runs();
	}

	$last_run = $last_runs[ $event->hook ] ?? null;

	if ( ! $last_run instanceof \DateTimeImmutable ) {
		return sprintf(
			'<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">%s</span>',
			esc_html__( 'Never', 'wp-crontrol' )
		);
	}

	$url = add_query_arg( [
		'page' => 'wp-crontrol-logs',
		'crontrol_hook' => rawurlencode( $event->hook ),
	], admin_url( 'tools.php' ) );

	$time = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'View logs', 'wp-crontrol' ),
	);

	$ago = sprintf(
		/* translators: %s: Time period, for example "8 minutes" */
		__( '%s ago', 'wp-crontrol' ),
		\Crontrol\interval( time() - (int) $last_run->format( 'U' ) )
	);

	return sprintf(
		'%1$s<br>%2$s',
		esc_html( $ago ),
		$time,
	);
}
