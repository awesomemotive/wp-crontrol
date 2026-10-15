<?php
/**
 * WordPress Abilities API integration (WordPress 6.9 and later).
 *
 * Lets AI assistants and other Abilities API clients read and manage cron events through the same
 * rules as WP Crontrol's admin screens. Nothing here creates or edits event code: PHP and URL cron
 * events can't be created, edited, or deleted through an ability.
 */

namespace Crontrol\Abilities;

use Crontrol\Context\WordPressFeatureContext;
use Crontrol\Context\WordPressUserContext;
use Crontrol\Event;
use Crontrol\Event\ActionSchedulerEvent;
use Crontrol\Event\CoreCronEvent;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;
use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\Log;
use Crontrol\Pro\QueryBuilder;
use WP_Error;

use function Crontrol\php_cron_events_enabled;
use function Crontrol\url_cron_events_enabled;

const CATEGORY = 'wp-crontrol';

// These actions only fire on WordPress 6.9 and later.
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_category' ); // @phpstan-ignore WPCompat.actionNotAvailable.wpabilitiesapicategoriesinit
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_abilities' ); // @phpstan-ignore WPCompat.actionNotAvailable.wpabilitiesapiinit

/**
 * Whether this site's WordPress has the Abilities API.
 */
function available(): bool {
	return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
}

/**
 * Whether cron event logging is available in this request. It isn't while the retired WP Crontrol
 * Pro plugin is still running instead of WP Crontrol's own logging.
 */
function logging_available(): bool {
	return class_exists( EventRunModel::class ) && function_exists( 'Crontrol\Pro\tables_ready' );
}

/**
 * Whether logging is on for some hooks but the log tables aren't ready yet, for example just after an
 * update and before the next admin or cron request upgrades them.
 */
function logs_upgrading(): bool {
	return logging_available() && array() !== Log::get_enabled_hooks();
}

/**
 * The message an ability returns when it has no logs to read.
 */
function logs_unavailable_message(): string {
	if ( logs_upgrading() ) {
		return __( 'Logging is on, but the cron event logs are being upgraded. Try again in a few minutes.', 'wp-crontrol' );
	}

	return __( 'Logging is off. Turn it on for the cron events you want to watch.', 'wp-crontrol' );
}

/**
 * Registers the ability category.
 */
function register_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => __( 'Cron', 'wp-crontrol' ),
			'description' => __( 'View, run, and pause cron events, and read their logs, with WP Crontrol.', 'wp-crontrol' ),
		)
	);
}

/**
 * Returns the definitions of every ability, keyed by name. Used for registration and for the
 * Cron AI screen, so the two never disagree.
 *
 * @return array<string,array<string,mixed>>
 */
function get_definitions(): array {
	$readonly = array(
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	);

	$event_ref = array(
		'hook' => array(
			'type'        => 'string',
			'description' => __( 'The hook name of the cron event.', 'wp-crontrol' ),
			'minLength'   => 1,
		),
		'sig'  => array(
			'type'        => 'string',
			'description' => __( 'The signature of the cron event, from the list of events.', 'wp-crontrol' ),
			'minLength'   => 1,
		),
	);

	return array(
		'wp-crontrol/list-events'      => array(
			'label'               => __( 'List cron events', 'wp-crontrol' ),
			'description'         => __( 'Lists scheduled cron events with their next run time, schedule, actions, and whether they are paused, late, logged, or have no action. Filter by hook name or type.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Only events whose hook name contains this text.', 'wp-crontrol' ),
					),
					'type'     => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'core', 'custom', 'php', 'url', 'no_action', 'paused', 'logged', 'late' ),
						'default'     => 'all',
						'description' => __( 'Which events to list.', 'wp-crontrol' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'execute_callback'    => __NAMESPACE__ . '\list_events',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/get-event'        => array(
			'label'               => __( 'Get a cron event', 'wp-crontrol' ),
			'description'         => __( 'Returns the details of one cron event, including the callbacks attached to its hook.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $event_ref,
				'required'             => array( 'hook', 'sig' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\get_event',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/list-schedules'   => array(
			'label'               => __( 'List cron schedules', 'wp-crontrol' ),
			'description'         => __( 'Lists the cron schedules (intervals) available on this site.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'execute_callback'    => __NAMESPACE__ . '\list_schedules',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/run-event'        => array(
			'label'               => __( 'Run a cron event now', 'wp-crontrol' ),
			'description'         => __( 'Runs a cron event now, the same way as Run now on the Cron Events screen. It runs in the background through WP-Cron, so check the logs for the outcome. The event keeps its normal schedule.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $event_ref,
				'required'             => array( 'hook', 'sig' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\run_event',
			'permission_callback' => __NAMESPACE__ . '\can_run_event',
			// Running an event runs whatever code is attached to it.
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		),
		'wp-crontrol/pause-hook'       => array(
			'label'               => __( 'Pause a cron event hook', 'wp-crontrol' ),
			'description'         => __( 'Pauses every event with this hook name. Paused events stay scheduled but do nothing when they run, until the hook is resumed.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array( 'hook' => $event_ref['hook'] ),
				'required'             => array( 'hook' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\pause_hook',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
		'wp-crontrol/resume-hook'      => array(
			'label'               => __( 'Resume a cron event hook', 'wp-crontrol' ),
			'description'         => __( 'Resumes a paused cron event hook, so its events do their work again.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array( 'hook' => $event_ref['hook'] ),
				'required'             => array( 'hook' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\resume_hook',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
		'wp-crontrol/list-logs'        => array(
			'label'               => __( 'List cron event logs', 'wp-crontrol' ),
			'description'         => __( 'Lists recent runs of logged cron events, newest first, with their status, duration, and first error. Only events with logging turned on are recorded.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hook'     => array(
						'type'        => 'string',
						'description' => __( 'Only runs of this hook.', 'wp-crontrol' ),
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'completed', 'errored', 'running' ),
					),
					'hours'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 720,
						'default'     => 168,
						'description' => __( 'How far back to look, in hours.', 'wp-crontrol' ),
					),
					'before'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Only runs older than this log ID, for the next page.', 'wp-crontrol' ),
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'execute_callback'    => __NAMESPACE__ . '\list_logs',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/get-log'          => array(
			'label'               => __( 'Get a cron event log', 'wp-crontrol' ),
			'description'         => __( 'Returns everything recorded for one run of a logged cron event: errors, log messages, duration, emails, HTTP requests, and database queries.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\get_log',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/get-insights'     => array(
			'label'               => __( 'Get cron insights', 'wp-crontrol' ),
			'description'         => __( 'Summarises logged cron event runs over the last 7 days: runs, failures, and the slowest hooks.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'execute_callback'    => __NAMESPACE__ . '\get_insights',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => $readonly,
		),
		'wp-crontrol/set-hook-logging' => array(
			'label'               => __( 'Turn logging on or off for a hook', 'wp-crontrol' ),
			'description'         => __( 'Turns cron event logging on or off for one hook. While logging is on, each run of the hook is recorded in the cron logs.', 'wp-crontrol' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hook'    => $event_ref['hook'],
					'enabled' => array(
						'type'        => 'boolean',
						'description' => __( 'True to log the hook, false to stop logging it.', 'wp-crontrol' ),
					),
				),
				'required'             => array( 'hook', 'enabled' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => __NAMESPACE__ . '\set_hook_logging',
			'permission_callback' => __NAMESPACE__ . '\can_manage',
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Registers the abilities.
 */
function register_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	foreach ( get_definitions() as $name => $definition ) {
		$name = strtolower( $name );

		if ( ! $name ) {
			continue;
		}

		$annotations = $definition['annotations'];
		unset( $definition['annotations'] );

		$definition['category'] = CATEGORY;
		$definition['meta']     = array(
			'annotations'  => $annotations,
			'show_in_rest' => true,
			'public'       => true,
		);

		wp_register_ability( $name, $definition );
	}
}

/**
 * Permission for the read-only abilities and logging settings: the same as WP Crontrol's screens.
 */
function can_manage(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Finds a scheduled event by its hook and signature.
 *
 * @param mixed $input The ability input.
 */
function find_event( $input ): ?Event\Event {
	$hook = is_array( $input ) && isset( $input['hook'] ) ? (string) $input['hook'] : '';
	$sig  = is_array( $input ) && isset( $input['sig'] ) ? (string) $input['sig'] : '';

	if ( '' === $hook || '' === $sig ) {
		return null;
	}

	foreach ( Event\get() as $event ) {
		if ( $event->hook === $hook && $event->sig === $sig ) {
			return $event;
		}
	}

	return null;
}

/**
 * Permission to run an event. Mirrors the Cron Events screen, and is stricter for PHP cron events:
 * running stored PHP code through an AI assistant also needs the `edit_files` capability.
 *
 * Permission callbacks only answer yes or no. Why an allowed event can't run (it's paused, or its
 * integrity check failed) is reported by the execute callback.
 *
 * @param mixed $input The ability input.
 */
function can_run_event( $input ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	$event = find_event( $input );

	if ( $event instanceof PHPCronEvent ) {
		return php_cron_events_enabled() && current_user_can( 'edit_files' );
	}

	if ( $event instanceof URLCronEvent ) {
		return url_cron_events_enabled();
	}

	return true;
}

/**
 * Whether a hook is paused.
 *
 * @param string $hook The hook name.
 */
function is_hook_paused( string $hook ): bool {
	$paused = get_option( \Crontrol\PAUSED_OPTION, array() );

	return is_array( $paused ) && array_key_exists( $hook, $paused );
}

/**
 * Checks that a hook can be paused or resumed. Mirrors the Cron Events screen: WP Crontrol's own PHP
 * and URL hooks can't be paused, and only hooks that have scheduled events (or are already paused)
 * can be used, so an ability can't switch off an arbitrary action such as `init`.
 *
 * @param string $hook   The hook name.
 * @param bool   $resume Whether the hook is being resumed.
 */
function check_pausable( string $hook, bool $resume ): ?WP_Error {
	if ( in_array( $hook, array( PHPCronEvent::HOOK_NAME, URLCronEvent::HOOK_NAME ), true ) ) {
		return new WP_Error( 'crontrol_hook_not_pausable', __( 'This hook can’t be paused or resumed.', 'wp-crontrol' ), array( 'status' => 400 ) );
	}

	if ( $resume ) {
		$paused = get_option( \Crontrol\PAUSED_OPTION, array() );

		if ( is_array( $paused ) && array_key_exists( $hook, $paused ) ) {
			return null;
		}
	}

	foreach ( Event\get() as $event ) {
		if ( $event->hook === $hook ) {
			return $event->pausable() ? null : new WP_Error( 'crontrol_hook_not_pausable', __( 'This hook can’t be paused or resumed.', 'wp-crontrol' ), array( 'status' => 400 ) );
		}
	}

	return new WP_Error( 'crontrol_hook_not_found', __( 'There are no scheduled cron events with this hook name.', 'wp-crontrol' ), array( 'status' => 404 ) );
}

/**
 * Returns the type of an event, as used by the abilities.
 *
 * @param Event\Event $event The event.
 */
function event_type( Event\Event $event ): string {
	if ( $event instanceof PHPCronEvent ) {
		return 'php';
	}

	if ( $event instanceof URLCronEvent ) {
		return 'url';
	}

	if ( $event instanceof ActionSchedulerEvent ) {
		return 'action_scheduler';
	}

	if ( $event instanceof CoreCronEvent ) {
		return 'core';
	}

	return 'custom';
}

/**
 * Returns a label for an event. For PHP and URL cron events it's built from the event's display
 * name only, never from its code or URL, which can hold secrets.
 *
 * @param Event\Event $event The event.
 * @param string      $type  The event type.
 */
function event_label( Event\Event $event, string $type ): string {
	if ( 'php' !== $type && 'url' !== $type ) {
		return $event->hook;
	}

	$name = crontrol_event_name( $event );

	if ( 'php' === $type ) {
		/* translators: %s: Name of the PHP cron event */
		return '' !== $name ? sprintf( __( 'PHP cron event (%s)', 'wp-crontrol' ), $name ) : __( 'PHP cron event', 'wp-crontrol' );
	}

	/* translators: %s: Name of the URL cron event */
	return '' !== $name ? sprintf( __( 'URL cron event (%s)', 'wp-crontrol' ), $name ) : __( 'URL cron event', 'wp-crontrol' );
}

/**
 * Returns the display name of a PHP or URL cron event, or an empty string.
 *
 * @param Event\Event $event The event.
 */
function crontrol_event_name( Event\Event $event ): string {
	$args = is_array( $event->args ) ? ( $event->args[0] ?? null ) : null;

	return ( is_array( $args ) && isset( $args['name'] ) && is_scalar( $args['name'] ) ) ? (string) $args['name'] : '';
}

/**
 * Converts an event into ability output. PHP code, full URLs, and integrity hashes are never included.
 *
 * @param Event\Event $event The event.
 *
 * @return array<string,mixed>
 */
function format_event( Event\Event $event ): array {
	$type    = event_type( $event );
	$actions = array();

	// A PHP or URL cron event whose stored data isn't the shape WP Crontrol saves is reported as an
	// error, rather than letting it break the whole list.
	$payload   = ( is_array( $event->args ) && is_array( $event->args[0] ?? null ) ) ? $event->args[0] : null;
	$malformed = in_array( $type, array( 'php', 'url' ), true ) && null === $payload;

	try {
		$schedule       = $event->schedule ? $event->get_schedule_name() : null;
		$schedule_error = false;
	} catch ( \Throwable $e ) {
		// The schedule's definition is gone, for example because the plugin that added it is inactive.
		$schedule       = $event->schedule;
		$schedule_error = true;
	}

	try {
		$has_error = $malformed || $event->has_error();
	} catch ( \Throwable $e ) {
		$has_error = true;
	}

	foreach ( $event->get_callbacks() as $callback ) {
		$actions[] = (string) ( $callback['callback']['name'] ?? '' );
	}

	$output = array(
		'hook'          => $event->hook,
		'sig'           => $event->sig,
		'type'          => $type,
		'label'         => event_label( $event, $type ),
		'next_run_utc'  => gmdate( 'c', $event->timestamp ),
		'next_run_site' => wp_date( 'c', $event->timestamp ),
		'schedule'      => $schedule,
		'schedule_missing' => $schedule_error,
		'interval'      => $event->interval,
		'recurring'     => $event->is_recurring(),
		'paused'        => $event->is_paused(),
		'late'          => $event->is_late(),
		'has_action'    => ! empty( $actions ),
		'actions'       => array_values( array_filter( $actions ) ),
		'has_error'     => $has_error,
		'logged'        => logging_available() && Log::is_hook_logged( $event->hook ),
	);

	if ( 'php' === $type ) {
		$output['name'] = crontrol_event_name( $event );
	} elseif ( 'url' === $type ) {
		$args             = $payload ?? array();
		$output['name']   = crontrol_event_name( $event );
		$output['url']    = Log::redact_url( is_string( $args['url'] ?? null ) ? $args['url'] : '' );
		$output['method'] = Event\url_method( $args['method'] ?? 'GET' );
	} else {
		$output['args'] = is_array( $event->args ) ? array_values( $event->args ) : array();
	}

	return $output;
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>
 */
function list_events( $input = array() ): array {
	$input    = is_array( $input ) ? $input : array();
	$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
	$type     = isset( $input['type'] ) ? (string) $input['type'] : 'all';
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = min( 200, max( 1, (int) ( $input['per_page'] ?? 50 ) ) );

	$events = Event\get();

	if ( '' !== $search ) {
		$events = Event\filter_by_search( $events, $search );
	}

	$events = array_values( $events );

	$events = array_values( array_filter(
		$events,
		static function ( Event\Event $event ) use ( $type ): bool {
			switch ( $type ) {
				case 'core':
					return $event instanceof CoreCronEvent;
				case 'custom':
					return ! ( $event instanceof CoreCronEvent );
				case 'php':
					return $event instanceof PHPCronEvent;
				case 'url':
					return $event instanceof URLCronEvent;
				case 'no_action':
					return empty( $event->get_callbacks() );
				case 'paused':
					return $event->is_paused();
				case 'logged':
					return logging_available() && Log::is_hook_logged( $event->hook );
				case 'late':
					return $event->is_late();
			}

			return true;
		}
	) );

	$total = count( $events );
	$slice = array_slice( $events, ( $page - 1 ) * $per_page, $per_page );

	return array(
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'events'   => array_map( __NAMESPACE__ . '\format_event', $slice ),
	);
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function get_event( $input ) {
	$event = find_event( $input );

	if ( ! $event ) {
		return new WP_Error( 'crontrol_event_not_found', __( 'The cron event could not be found. It may have already run.', 'wp-crontrol' ), array( 'status' => 404 ) );
	}

	return format_event( $event );
}

/**
 * @return array<string,mixed>
 */
function list_schedules(): array {
	$schedules = array();

	foreach ( \Crontrol\Schedule\get() as $schedule ) {
		$schedules[] = array(
			'name'     => $schedule->name,
			'display'  => $schedule->display,
			'interval' => $schedule->interval,
			'in_use'   => $schedule->is_in_use(),
		);
	}

	return array( 'schedules' => $schedules );
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function run_event( $input ) {
	$event = find_event( $input );

	if ( ! $event ) {
		return new WP_Error( 'crontrol_event_not_found', __( 'The cron event could not be found. It may have already run.', 'wp-crontrol' ), array( 'status' => 404 ) );
	}

	try {
		$runnable = $event->runnable( new WordPressUserContext(), new WordPressFeatureContext() );
	} catch ( \Throwable $e ) {
		// The event's stored data isn't the shape WP Crontrol saves.
		$runnable = false;
	}

	if ( ! $runnable ) {
		return new WP_Error( 'crontrol_event_not_runnable', __( 'This cron event can’t be run. It may be paused, or it may need to be checked and saved again on the Cron Events screen.', 'wp-crontrol' ), array( 'status' => 409 ) );
	}

	$ran = Event\run( $event->hook, $event->sig );

	if ( is_wp_error( $ran ) ) {
		return $ran;
	}

	return array(
		'scheduled' => true,
		'hook'      => $event->hook,
		'logged'    => logging_available() && Log::is_hook_logged( $event->hook ),
		'message'   => __( 'The event was sent to WP-Cron to run now. It runs in the background, so check the cron logs for the outcome.', 'wp-crontrol' ),
	);
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function pause_hook( $input ) {
	$hook  = (string) $input['hook'];
	$error = check_pausable( $hook, false );

	if ( $error ) {
		return $error;
	}

	// Already paused: nothing to do, which keeps the ability idempotent.
	if ( is_hook_paused( $hook ) ) {
		return array(
			'hook'   => $hook,
			'paused' => true,
		);
	}

	$paused = Event\pause( $hook );

	if ( is_wp_error( $paused ) ) {
		return $paused;
	}

	/** This action is documented in src/bootstrap.php */
	do_action( 'crontrol/paused_hook', $hook );

	return array(
		'hook'   => $hook,
		'paused' => true,
	);
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function resume_hook( $input ) {
	$hook  = (string) $input['hook'];
	$error = check_pausable( $hook, true );

	if ( $error ) {
		return $error;
	}

	// Not paused: nothing to do, which keeps the ability idempotent.
	if ( ! is_hook_paused( $hook ) ) {
		return array(
			'hook'   => $hook,
			'paused' => false,
		);
	}

	$resumed = Event\resume( $hook );

	if ( is_wp_error( $resumed ) ) {
		return $resumed;
	}

	/** This action is documented in src/bootstrap.php */
	do_action( 'crontrol/resumed_hook', $hook );

	return array(
		'hook'   => $hook,
		'paused' => false,
	);
}

/**
 * Converts a log entry into ability output. Event arguments are only included when they were
 * stored, which they aren't by default.
 *
 * @param EventRun $run     The run.
 * @param bool     $details Whether to include every error and log message.
 * @return array<string,mixed>
 */
function format_run( EventRun $run, bool $details = false ): array {
	$output = array(
		'id'             => $run->ID,
		'hook'           => $run->hook,
		'status'         => $run->status,
		'fatal'          => $run->fatal,
		'warning'        => $run->warning,
		'started_at_utc' => $run->started_at->format( 'c' ),
		'duration_ms'    => $run->duration,
		'actions'        => $run->actions,
		'emails'         => $run->emails,
		'emails_failed'  => $run->mail_failed,
		'http_requests'  => $run->https,
		'db_queries'     => $run->db_queries,
		'error_count'    => count( $run->errors ),
		'first_error'    => isset( $run->errors[0]['message'] ) ? (string) $run->errors[0]['message'] : null,
	);

	if ( $details ) {
		$output['schedule']    = $run->schedule;
		$output['memory_peak'] = $run->memory;
		$output['errors']      = $run->errors;
		$output['messages']    = $run->logs;
		$output['args']        = array_values( $run->args );
	}

	return $output;
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>
 */
function list_logs( $input = array() ): array {
	$input = is_array( $input ) ? $input : array();

	if ( ! logging_available() || ! \Crontrol\Pro\tables_ready() ) {
		return array(
			'logging_enabled' => logs_upgrading(),
			'logged_hooks'    => logs_upgrading() ? Log::get_enabled_hooks() : array(),
			'runs'            => array(),
			'next_before'     => null,
			'message'         => logs_unavailable_message(),
		);
	}

	$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	$hours    = min( 720, max( 1, (int) ( $input['hours'] ?? 168 ) ) );
	$since    = new \DateTimeImmutable( sprintf( '-%d hours', $hours ), new \DateTimeZone( 'UTC' ) );

	$query = EventRunModel::query()
		->where( 'started_at', QueryBuilder::OPERATOR_GREATER_THAN_OR_EQUAL, $since )
		->per_page( $per_page + 1 );

	if ( ! empty( $input['hook'] ) ) {
		$query->where( 'hook', QueryBuilder::OPERATOR_EQUALS, (string) $input['hook'] );
	}

	if ( ! empty( $input['status'] ) ) {
		$query->where( 'status', QueryBuilder::OPERATOR_EQUALS, (string) $input['status'] );
	}

	if ( ! empty( $input['before'] ) ) {
		$query->before( (int) $input['before'] );
	}

	$runs = $query->get();
	$more = count( $runs ) > $per_page;
	$runs = array_slice( $runs, 0, $per_page );
	$last = end( $runs );

	return array(
		'logging_enabled' => true,
		'logged_hooks'    => Log::get_enabled_hooks(),
		'runs'            => array_map( __NAMESPACE__ . '\format_run', $runs ),
		'next_before'     => ( $more && $last ) ? $last->ID : null,
	);
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function get_log( $input ) {
	$not_found = new WP_Error( 'crontrol_log_not_found', __( 'The cron event log could not be found.', 'wp-crontrol' ), array( 'status' => 404 ) );

	if ( ! logging_available() || ! \Crontrol\Pro\tables_ready() ) {
		return $not_found;
	}

	// The query builder limits results to the current site, unlike a lookup by ID.
	$runs = EventRunModel::query()
		->where( 'ID', QueryBuilder::OPERATOR_EQUALS, (int) $input['id'] )
		->per_page( 1 )
		->get();

	if ( ! $runs ) {
		return $not_found;
	}

	return format_run( $runs[0], true );
}

/**
 * @return array<string,mixed>
 */
function get_insights(): array {
	if ( ! logging_available() || ! \Crontrol\Pro\tables_ready() ) {
		return array(
			'logging_enabled' => logs_upgrading(),
			'days'            => 7,
			'hooks'           => array(),
			'message'         => logs_unavailable_message(),
		);
	}

	$wpdb  = EventRunModel::wpdb();
	$since = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	$where = '%i >= %s';
	$args  = array( 'started_at', $since );

	if ( is_multisite() ) {
		$where .= ' AND %i = %d';
		$args[] = 'site_id';
		$args[] = get_current_blog_id();
	}

	$table = EventRunModel::get_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where only contains placeholders.
	$totals = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS runs, SUM( status = 'errored' ) AS failures, COUNT( DISTINCT hook ) AS hooks FROM %i WHERE {$where}",
			$table,
			...$args
		),
		ARRAY_A
	);

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT hook, COUNT(*) AS runs, SUM( status = 'errored' ) AS failures, SUM( fatal ) AS fatals, AVG( duration ) AS avg_ms, MAX( duration ) AS max_ms
			FROM %i
			WHERE {$where}
			GROUP BY hook
			ORDER BY failures DESC, runs DESC
			LIMIT 50",
			$table,
			...$args
		),
		ARRAY_A
	);

	$slowest = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT hook FROM %i WHERE {$where} GROUP BY hook ORDER BY AVG( duration ) DESC LIMIT 5",
			$table,
			...$args
		)
	);
	// phpcs:enable

	$hooks = array();

	foreach ( (array) $rows as $row ) {
		$hooks[] = array(
			'hook'     => (string) $row['hook'],
			'runs'     => (int) $row['runs'],
			'failures' => (int) $row['failures'],
			'fatals'   => (int) $row['fatals'],
			'avg_ms'   => (int) round( (float) $row['avg_ms'] ),
			'max_ms'   => (int) $row['max_ms'],
		);
	}

	$hook_count = (int) ( $totals['hooks'] ?? 0 );

	return array(
		'logging_enabled' => true,
		'days'            => 7,
		'runs'            => (int) ( $totals['runs'] ?? 0 ),
		'failures'        => (int) ( $totals['failures'] ?? 0 ),
		'hook_count'      => $hook_count,
		// The breakdown lists up to 50 hooks, most failures first. The totals and slowest hooks cover every hook.
		'hooks'           => $hooks,
		'hooks_truncated' => $hook_count > count( $hooks ),
		'slowest_hooks'   => array_map( 'strval', (array) $slowest ),
	);
}

/**
 * @param mixed $input The ability input.
 * @return array<string,mixed>|WP_Error
 */
function set_hook_logging( $input ) {
	$hook    = (string) $input['hook'];
	$enabled = (bool) $input['enabled'];

	if ( ! logging_available() ) {
		return new WP_Error( 'crontrol_logging_unavailable', __( 'Cron event logging isn’t available on this site right now.', 'wp-crontrol' ), array( 'status' => 409 ) );
	}

	if ( in_array( $hook, \Crontrol\Pro\INTERNAL_HOOKS, true ) || ! Log::is_loggable_hook( $hook ) ) {
		return new WP_Error( 'crontrol_hook_not_loggable', __( 'WP Crontrol’s own maintenance events can’t be logged.', 'wp-crontrol' ), array( 'status' => 400 ) );
	}

	// Logging can be turned on only for hooks that have scheduled cron events. Turning it off always works.
	if ( $enabled && ! in_array( $hook, array_map( static fn ( Event\Event $event ): string => $event->hook, Event\get() ), true ) ) {
		return new WP_Error( 'crontrol_hook_not_found', __( 'There are no scheduled cron events with this hook name.', 'wp-crontrol' ), array( 'status' => 404 ) );
	}

	if ( $enabled ) {
		\Crontrol\Pro\init_db();
	}

	Log::set_logging_for_hook( $hook, $enabled );

	return array(
		'hook'   => $hook,
		'logged' => Log::is_hook_logged( $hook ),
	);
}
