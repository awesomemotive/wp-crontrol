<?php
/**
 * Functions related to cron events.
 */

namespace Crontrol\Event;

use Crontrol\Context\WordPressFeatureContext;
use Crontrol\Context\WordPressUserContext;
use Crontrol\Exception\InvalidURLException;
use WP_Error;

use const Crontrol\PAUSED_OPTION;

/**
 * Executes a cron event immediately.
 *
 * Executes an event by scheduling a new single event with the same arguments.
 *
 * @param string $hookname The hook name of the cron event to run.
 * @param string $sig      The cron event signature.
 * @return true|WP_Error True if the execution was successful, WP_Error if not.
 */
function run( $hookname, $sig ) {
	$crons = get_core_cron_array();

	foreach ( $crons as $cron ) {
		if ( isset( $cron[ $hookname ][ $sig ] ) ) {
			$data = $cron[ $hookname ][ $sig ];

			$event = Event::create_immediate( $hookname, $data['args'] );

			delete_transient( 'doing_cron' );
			$scheduled = force_schedule_single_event( $hookname, $event->args ); // UTC

			if ( is_wp_error( $scheduled ) ) {
				return $scheduled;
			}

			add_filter( 'cron_request', function ( array $cron_request_array ) {
				$cron_request_array['url'] = add_query_arg( 'crontrol-single-event', 1, $cron_request_array['url'] );
				return $cron_request_array;
			} );

			spawn_cron();

			sleep( 1 );

			/**
			 * Fires after a cron event is scheduled to run manually.
			 *
			 * @param Event $event An object containing the event's data.
			 */
			do_action( 'crontrol/ran_event', $event );

			return true;
		}
	}

	return new WP_Error(
		'not_found',
		sprintf(
			/* translators: %s: The name of the cron event. */
			__( 'The cron event %s could not be found.', 'wp-crontrol' ),
			$hookname
		)
	);
}

/**
 * Forcibly schedules a single event for the purpose of manually running it.
 *
 * This is used instead of `wp_schedule_single_event()` to avoid the duplicate check that's otherwise performed.
 *
 * @param string  $hook Action hook to execute when the event is run.
 * @param mixed[] $args Optional. Array containing each separate argument to pass to the hook's callback function.
 * @return true|WP_Error True if event successfully scheduled. WP_Error on failure.
 */
function force_schedule_single_event( $hook, $args = array() ) {
	$event = (object) array(
		'hook'      => $hook,
		'timestamp' => 1,
		'schedule'  => false,
		'args'      => $args,
	);
	$crons = get_core_cron_array();
	$key   = md5( serialize( $event->args ) );

	$crons[ $event->timestamp ][ $event->hook ][ $key ] = array(
		'schedule' => $event->schedule,
		'args'     => $event->args,
	);
	ksort( $crons );

	$result = _set_cron_array( $crons );

	// Not using the WP_Error from `_set_cron_array()` here so we can provide a more specific error message.
	if ( false === $result ) {
		return new WP_Error(
			'could_not_add',
			sprintf(
				/* translators: %s: The name of the cron event. */
				__( 'Failed to schedule the cron event %s.', 'wp-crontrol' ),
				$hook
			)
		);
	}

	return true;
}

/**
 * Adds a new cron event.
 *
 * @param string  $next_run_local The time that the event should be run at, in the site's timezone.
 * @param string  $schedule       The schedule of the cron event.
 * @param string  $hook           The name of the hook to execute.
 * @param mixed[] $args           Arguments to add to the cron event.
 * @phpstan-param list<mixed> $args
 * @return true|WP_error True if the addition was successful, WP_Error otherwise.
 */
function add( $next_run_local, $schedule, $hook, array $args ) {
	/**
	 * @var int
	 */
	$current_time = current_time( 'timestamp' );
	$next_run_local = strtotime( $next_run_local, $current_time );

	if ( false === $next_run_local ) {
		return new WP_Error(
			'invalid_timestamp',
			__( 'Invalid timestamp provided.', 'wp-crontrol' )
		);
	}

	$next_run_utc = (int) get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $next_run_local ), 'U' );
	$error = null;

	if ( PHPCronEvent::HOOK_NAME === $hook && ! empty( $args[0]['code'] ) ) {
		try {
			/**
			 * The call to `eval()` below checks the syntax of the PHP code provided in the cron event. This is done to
			 * add a flag to a cron event that contains invalid PHP code, so that the user can be informed of the syntax
			 * error when viewing the event in the list table.
			 *
			 * Security: The code is not executed due to the early return statement that precedes it. The code is only
			 * checked for syntax correctness.
			 */
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( sprintf(
				'return true; %s',
				$args[0]['code']
			) );
		} catch ( \ParseError $e ) {
			$args[0]['syntax_error_message'] = $e->getMessage();
			$args[0]['syntax_error_line'] = $e->getLine();
			$error = $e;
		}
	}

	if ( URLCronEvent::HOOK_NAME === $hook && ! empty( $args[0]['url'] ) ) {
		try {
			validate_url( $args[0]['url'] );
		} catch ( InvalidURLException $e ) {
			$args[0]['url_error_message'] = $e->getMessage();
			$error = $e;
		}
	}

	if ( '_oneoff' === $schedule || '' === $schedule ) {
		$result = wp_schedule_single_event( $next_run_utc, $hook, $args, true );
	} else {
		$result = wp_schedule_event( $next_run_utc, $schedule, $hook, $args, true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return ( $error instanceof \Throwable ) ? new WP_Error(
		'has_error',
		sprintf(
			/* translators: %s: The error message. */
			__( 'The cron event was saved but contains an error: %s.', 'wp-crontrol' ),
			$error->getMessage(),
		),
	) : true;
}

/**
 * Deletes a cron event.
 *
 * @param string $hook         The hook name of the event to delete.
 * @param string $sig          The cron event signature.
 * @param string $next_run_utc The UTC time that the event would be run at.
 * @return true|WP_Error True if the deletion was successful, WP_Error otherwise.
 */
function delete( $hook, $sig, $next_run_utc ) {
	$event = get_single( $hook, $sig, $next_run_utc );

	if ( is_wp_error( $event ) ) {
		return $event;
	}

	$unscheduled = wp_unschedule_event( $event->timestamp, $event->hook, $event->args, true );

	if ( is_wp_error( $unscheduled ) ) {
		return $unscheduled;
	}

	return true;
}

/**
 * Pauses a cron event.
 *
 * @param string $hook The hook name of the event to pause.
 * @return true|WP_Error True if the pause was successful, WP_Error otherwise.
 */
function pause( $hook ) {
	$paused = get_option( PAUSED_OPTION, array() );

	if ( ! is_array( $paused ) ) {
		$paused = array();
	}

	$paused[ $hook ] = true;

	$result = update_option( PAUSED_OPTION, $paused, true );

	if ( false === $result ) {
		return new WP_Error(
			'could_not_pause',
			sprintf(
				/* translators: %s: The name of the cron event. */
				__( 'Failed to pause the cron event %s.', 'wp-crontrol' ),
				$hook
			)
		);
	}

	return true;
}

/**
 * Resumes a paused cron event.
 *
 * @param string $hook The hook name of the event to resume.
 * @return true|WP_Error True if the resumption was successful, WP_Error otherwise.
 */
function resume( $hook ) {
	$paused = get_option( PAUSED_OPTION );

	if ( ! is_array( $paused ) || ( count( $paused ) === 0 ) ) {
		return true;
	}

	unset( $paused[ $hook ] );

	$result = update_option( PAUSED_OPTION, $paused, true );

	if ( false === $result ) {
		return new WP_Error(
			'could_not_resume',
			sprintf(
				/* translators: %s: The name of the cron event. */
				__( 'Failed to resume the cron event %s.', 'wp-crontrol' ),
				$hook
			)
		);
	}

	return true;
}

/**
 * Returns a flattened array of cron events.
 *
 * @return array<string,Event> An array of cron event objects keyed by unique signature.
 */
function get() {
	$crons  = get_core_cron_array();
	$events = array();

	if ( empty( $crons ) ) {
		return array();
	}

	foreach ( $crons as $time => $cron ) {
		foreach ( $cron as $hook => $dings ) {
			foreach ( $dings as $sig => $data ) {
				$events[ "$hook-$sig-$time" ] = Event::create(
					$hook,
					$time,
					$sig,
					$data['args'],
					$data['schedule'] ?: null,
					$data['interval'] ?? null,
				);
			}
		}
	}

	// Ensure events are always returned in date descending order.
	// External cron runners such as Cavalcade don't guarantee events are returned in order of time.
	uasort( $events, 'Crontrol\Event\uasort_order_events' );

	return $events;
}

/**
 * Finds a single matching cron event by hook, timestamp, and signature.
 *
 * @param string     $hook      The hook name of the event.
 * @param int        $timestamp The UTC timestamp when the event would be run at.
 * @param string     $sig       The event signature.
 * @return Event|null A cron event object, or null if it's not found.
 */
function find( string $hook, int $timestamp, string $sig ): ?Event {
	$crons = get_core_cron_array();

	if ( ! isset( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
		return null;
	}

	$data = $crons[ $timestamp ][ $hook ][ $sig ];

	return Event::create(
		$hook,
		$timestamp,
		$sig,
		$data['args'],
		$data['schedule'] ?: null,
		$data['interval'] ?? null,
	);
}

/**
 * Gets a single cron event.
 *
 * @param string     $hook         The hook name of the event.
 * @param string     $sig          The event signature.
 * @param string|int $next_run_utc The UTC time that the event would be run at.
 * @return Event|WP_Error A cron event object, or a WP_Error if it's not found.
 */
function get_single( $hook, $sig, $next_run_utc ) {
	$crons = get_core_cron_array();
	$next_run_utc = (int) $next_run_utc;

	if ( isset( $crons[ $next_run_utc ][ $hook ][ $sig ] ) ) {
		$data = $crons[ $next_run_utc ][ $hook ][ $sig ];

		return Event::create(
			$hook,
			$next_run_utc,
			$sig,
			$data['args'],
			$data['schedule'] ?: null,
			$data['interval'] ?? null,
		);
	}

	return new WP_Error(
		'not_found',
		sprintf(
			/* translators: %s: The name of the cron event. */
			__( 'The cron event %s could not be found.', 'wp-crontrol' ),
			$hook
		)
	);
}

/**
 * Returns an array of the number of events for each hook.
 *
 * @return array<string,int> Array of number of events for each hook, keyed by the hook name.
 */
function count_by_hook() {
	$crons  = get_core_cron_array();
	$events = array();

	if ( empty( $crons ) ) {
		return array();
	}

	foreach ( $crons as $time => $cron ) {
		foreach ( $cron as $hook => $dings ) {
			if ( ! isset( $events[ $hook ] ) ) {
				$events[ $hook ] = 0;
			}

			$events[ $hook ] += count( $dings );
		}
	}

	return $events;
}

/**
 * Event filtering, pagination, and manipulation functions.
 */

/**
 * Filters events to include only those with hook names that appear more than once.
 *
 * @param array<string,Event> $events The list of all events.
 * @return array<string,Event> Array of events with duplicated hook names.
 */
function filter_duplicated( array $events ): array {
	$hook_counts = count_by_hook();

	return array_filter(
		$events,
		fn( $event ) => isset( $hook_counts[ $event->hook ] ) && $hook_counts[ $event->hook ] > 1
	);
}

/**
 * Filters events by search term matching the hook name.
 *
 * @param array<string,Event> $events The list of all events.
 * @param string              $search Search term to filter by.
 * @return array<string,Event> Array of events matching the search term.
 */
function filter_by_search( array $events, string $search ): array {
	return array_filter(
		$events,
		fn( $event ) => false !== strpos( $event->hook, $search )
	);
}

/**
 * Paginates events for display.
 *
 * @param array<string,Event> $events   Array of events to paginate.
 * @param int                 $page_num Current page number (1-indexed).
 * @param int                 $per_page Number of events per page.
 * @return list<Event> Paginated array of events.
 */
function paginate( array $events, int $page_num, int $per_page ): array {
	$offset = ( $page_num - 1 ) * $per_page;
	return array_values( array_slice( $events, $offset, $per_page ) );
}

/**
 * Checks if any events have integrity failures.
 *
 * @param array<string,Event> $events Array of events to check.
 */
function has_integrity_failures( array $events ): bool {
	return (bool) array_filter( array_map(
		fn( $event ) => $event->integrity_failed(),
		$events
	) );
}

/**
 * Checks the integrity of a string compared to its stored hash.
 *
 * @param string|null $value       The string value.
 * @param string|null $stored_hash The stored HMAC of the code.
 * @return bool
 */
function check_integrity( $value, $stored_hash ): bool {
	// If there's no value or hash then the integrity check is not ok.
	if ( empty( $value ) || empty( $stored_hash ) ) {
		return false;
	}

	$value_hash = wp_hash( $value );

	// If the hashes match then the integrity check is ok.
	return hash_equals( $stored_hash, $value_hash );
}

/**
 * Initialises and returns the list table for events.
 *
 * @return Table The list table.
 */
function get_list_table() {
	static $table = null;

	if ( ! $table ) {
		$table = new Table( new WordPressUserContext(), new WordPressFeatureContext() );
		$table->prepare_items();
	}

	return $table;
}

/**
 * Order events function.
 *
 * The comparison function returns an integer less than, equal to, or greater than zero if the first argument is
 * considered to be respectively less than, equal to, or greater than the second.
 *
 * @param Event $a The first event to compare.
 * @param Event $b The second event to compare.
 * @return int
 */
function uasort_order_events( Event $a, Event $b ): int {
	$orderby = ( ! empty( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ) ? sanitize_text_field( $_GET['orderby'] ) : 'crontrol_next';
	$order   = ( ! empty( $_GET['order'] ) && is_string( $_GET['order'] ) ) ? sanitize_text_field( $_GET['order'] ) : 'asc';
	$compare = 0;

	switch ( $orderby ) {
		case 'crontrol_hook':
			if ( 'asc' === $order ) {
				$compare = strcmp( $a->hook, $b->hook );
			} else {
				$compare = strcmp( $b->hook, $a->hook );
			}
			break;
		case 'crontrol_schedule':
			if ( 'asc' === $order ) {
				$compare = ( $a->interval ?? 0 ) <=> ( $b->interval ?? 0 );
			} else {
				$compare = ( $b->interval ?? 0 ) <=> ( $a->interval ?? 0 );
			}
			break;
		default:
			if ( 'asc' === $order ) {
				$compare = $a->timestamp <=> $b->timestamp;
			} else {
				$compare = $b->timestamp <=> $a->timestamp;
			}
			break;
	}

	return $compare;
}

/**
 * Fetches the list of cron events from WordPress core.
 *
 * @return array<int,array<string,array<string,mixed[]>>>
 * @phpstan-return array<int,array<string,array<string,array{
 *     args: mixed[],
 *     schedule: string|false,
 *     interval?: int,
 * }>>>
 */
function get_core_cron_array() {
	$crons = _get_cron_array();

	if ( empty( $crons ) ) {
		$crons = array();
	}

	return $crons;
}

/**
 * Validates a URL for a cron event.
 *
 * @see https://github.com/WordPress/wordpress-develop/blob/197f0a71ad27d0688b6380c869aeaf92addd1451/src/wp-includes/class-wp-http.php#L283-L299
 *
 * @throws \Crontrol\Exception\InvalidURLException If the URL is invalid or not allowed.
 *
 * @param string $url The URL to validate.
 */
function validate_url( string $url ): void {
	$valid = wp_http_validate_url( $url );

	if ( $valid === false ) {
		throw new InvalidURLException(
			sprintf(
				/* translators: %s: The URL that failed validation. */
				__( 'The URL "%s" is not allowed', 'wp-crontrol' ),
				$url,
			)
		);
	}
}
