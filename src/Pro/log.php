<?php
/**
 * Functions related to logging cron events.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Pro;

use Throwable;
use Exception;

/**
 * Main class which encapsulates cron event logging functionality.
 */
class Log {

	/**
	 * Runs in progress, innermost last.
	 *
	 * @var array<int, LogRun>
	 */
	private array $stack = array();

	/**
	 * Old exception handler to restore.
	 *
	 * @var callable|null
	 */
	private $old_exception_handler = null;

	/**
	 * Whether the shutdown handler has been registered for this request.
	 */
	private bool $shutdown_registered = false;

	/**
	 * Whether this plugin's exception handler is on PHP's handler stack.
	 */
	private bool $handler_installed = false;

	/**
	 * Whether the request-wide listeners are attached.
	 */
	private bool $listening = false;

	/**
	 * The start and end callbacks attached to each observed hook.
	 *
	 * @var array<string, array{0: \Closure, 1: \Closure}>
	 */
	private array $observers = array();

	/**
	 * Object IDs of every start and end callback currently attached by any Log instance.
	 *
	 * WP Crontrol uses this to leave its own observers out of a hook's list of actions, so a hook
	 * with no other callbacks still shows the "no action" warning.
	 *
	 * @var array<int, true>
	 */
	private static array $observer_ids = array();

	/**
	 * The callbacks that route logged messages to the run in progress, keyed by hook name.
	 *
	 * @var array<string, \Closure>
	 */
	private array $routes = array();

	/**
	 * Sets up actions and filters for the cron event logging.
	 */
	public function init(): void {
		// WordPress.com VIP specific functionality:
		add_action( 'a8c_cron_control_event_threw_catchable_error', array( $this, 'action_cron_control_error' ), 10, 2 );

		$this->setup_hooks();
	}

	/**
	 * Sets up the hooks needed to log cron events as they run.
	 */
	public function setup_hooks(): void {
		array_map( array( $this, 'observe' ), self::get_enabled_hooks() );
	}

	/**
	 * Initialises the observance of the given hook.
	 *
	 * @param string $hook Hook name to observe.
	 */
	public function observe( string $hook ): void {
		if ( isset( $this->observers[ $hook ] ) || ! self::is_loggable_hook( $hook ) ) {
			return;
		}

		// The callbacks know which hook they belong to, rather than relying on current_filter(),
		// which WordPress leaves wrong when an exception escapes a nested hook.
		// Both callbacks are called from the same place for one invocation of the hook, so the
		// depth of the PHP call stack identifies the invocation, even when the hook recurses.
		$start = function ( ...$hook_args ) use ( $hook ): void {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$this->log_start( $hook, $hook_args, count( $trace ), self::trace_signature( array_slice( $trace, 1 ) ) );
		};
		$end = function () use ( $hook ): void {
			$this->log_end( $hook, count( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ) );
		};

		add_action( $hook, $start, -9999, 99 );
		add_action( $hook, $end, 9999, 0 );

		$this->observers[ $hook ] = array( $start, $end );

		self::$observer_ids[ spl_object_id( $start ) ] = true;
		self::$observer_ids[ spl_object_id( $end ) ]   = true;
	}

	/**
	 * Whether the given callback is one of the logging observers attached to a hook.
	 *
	 * @param mixed $callback A callback from the hook's list of callbacks.
	 */
	public static function is_observer( $callback ): bool {
		return $callback instanceof \Closure && isset( self::$observer_ids[ spl_object_id( $callback ) ] );
	}

	/**
	 * Stops observing the given hook.
	 *
	 * @param string $hook Hook name.
	 */
	public function unobserve( string $hook ): void {
		if ( ! isset( $this->observers[ $hook ] ) ) {
			return;
		}

		remove_action( $hook, $this->observers[ $hook ][0], -9999 );
		remove_action( $hook, $this->observers[ $hook ][1], 9999 );

		unset(
			self::$observer_ids[ spl_object_id( $this->observers[ $hook ][0] ) ],
			self::$observer_ids[ spl_object_id( $this->observers[ $hook ][1] ) ],
			$this->observers[ $hook ]
		);
	}

	/**
	 * Records every currently scheduled hook so the logging settings can list hooks that are no longer scheduled.
	 *
	 * Runs from the maintenance event and the Cron Logs screen, never on every request.
	 */
	public static function record_known_hooks(): void {
		$seen = get_option( 'crontrol_log_all_hooks', array() );
		$seen = is_array( $seen ) ? array_values( array_filter( $seen, 'is_string' ) ) : array();
		$new  = $seen;

		foreach ( \Crontrol\Event\get() as $event ) {
			if ( ! in_array( $event->hook, $new, true ) ) {
				$new[] = $event->hook;
			}
		}

		if ( $new !== $seen || false === get_option( 'crontrol_log_all_hooks', false ) ) {
			update_option( 'crontrol_log_all_hooks', $new, false );
		}
	}

	/**
	 * Whether the current request runs cron events, so that a logged hook fired during a page load
	 * by some other code is not recorded as a cron event run.
	 */
	public static function is_cron_context(): bool {
		$doing_cron = wp_doing_cron();

		/**
		 * Filters whether cron event runs in the current request should be logged.
		 *
		 * @param bool $doing_cron Whether the request is running cron events.
		 */
		return (bool) apply_filters( 'crontrol/should_log', $doing_cron );
	}

	/**
	 * The Cron Control runner catches fatal errors and uncaught exceptions itself, so this logs them in the Crontrol log.
	 *
	 * @param object              $event The Cron Control event.
	 * @param Throwable|Exception $error The exception or error.
	 */
	public function action_cron_control_error( $event, $error ): void {
		do_action( 'crontrol/error', $error );
	}

	/**
	 * Determines whether a running job has stalled.
	 *
	 * A job which has been running for longer than an hour is considered stalled.
	 *
	 * @param EventRun $run The event run.
	 */
	public static function has_stalled( EventRun $run ): bool {
		if ( ! $run->is_running() ) {
			return false;
		}

		$started_timestamp = $run->started_at->getTimestamp();
		$now_timestamp = time();

		if ( ( $now_timestamp - $started_timestamp ) > HOUR_IN_SECONDS ) {
			return true;
		}

		return false;
	}

	/**
	 * Reduces a URL to its scheme, host and port before it is logged, because credentials, paths,
	 * query strings and fragments often carry API keys and tokens.
	 *
	 * @param string $url The URL.
	 * @return string The URL without sensitive parts.
	 */
	public static function redact_url( $url ): string {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[redacted URL]';
		}

		$redacted = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$redacted .= ':' . $parts['port'];
		}

		$path = $parts['path'] ?? '';

		if ( ( '' !== $path && '/' !== $path ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			$redacted .= '/[redacted]';
		}

		return $redacted;
	}

	/**
	 * Turns a backtrace into a list of call sites, outermost first.
	 *
	 * @param array<int, array<string, mixed>> $trace A backtrace from debug_backtrace().
	 * @return list<string>
	 */
	private static function trace_signature( array $trace ): array {
		$signature = array();

		foreach ( array_reverse( $trace ) as $frame ) {
			$signature[] = ( $frame['file'] ?? '' ) . ':' . ( $frame['line'] ?? '' ) . ':' . ( $frame['class'] ?? '' ) . ( $frame['function'] ?? '' );
		}

		return $signature;
	}

	/**
	 * The innermost run still in progress, after finishing any run whose hook is no longer on
	 * the call stack because an exception left it and was caught. Used before attributing
	 * messages, HTTP requests and emails, so that they go to the right run.
	 */
	private function active(): ?LogRun {
		if ( ! $this->stack ) {
			return null;
		}

		$now = self::trace_signature( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) );

		while ( $this->stack ) {
			$top = end( $this->stack );

			if ( $top->ended ) {
				array_pop( $this->stack );
				continue;
			}

			if ( array_slice( $now, 0, count( $top->frames ) ) === $top->frames ) {
				return $top;
			}

			$this->abandon( array_pop( $this->stack ) );
		}

		$this->stop_listening();

		return null;
	}

	/**
	 * Records a run that ended because an exception was thrown in it and caught by the code
	 * that fired its hook.
	 *
	 * @param LogRun $run The run.
	 */
	private function abandon( LogRun $run ): void {
		if ( $run->ended ) {
			return;
		}

		$run->run->errors[] = array(
			'level'   => Logger::ERROR,
			'message' => __( 'The run ended early because an exception was thrown and caught by the code that fired this hook.', 'wp-crontrol' ),
		);

		$this->finish( $run, true );
	}

	/**
	 * Passes a logged message to the run in progress.
	 *
	 * @param string  $method The Logger method.
	 * @param mixed[] $args   The arguments for it.
	 */
	private function route( string $method, array $args ): void {
		$current = $this->active();

		if ( $current ) {
			$current->logger->{$method}( ...$args );
		}
	}

	/**
	 * The innermost run being logged, if any.
	 */
	private function current(): ?LogRun {
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( ! $this->stack[ $i ]->ended ) {
				return $this->stack[ $i ];
			}
		}

		return null;
	}

	/**
	 * Filter the arguments used in an HTTP request.
	 *
	 * Used to log the request, and to add the logging key to the arguments array.
	 *
	 * @param  mixed[] $args HTTP request arguments.
	 * @param  string  $url  The request URL.
	 * @return mixed[] HTTP request arguments.
	 */
	public function filter_http_request_args( array $args, $url ): array {
		$current = $this->active();

		if ( ! $current ) {
			return $args;
		}

		// Only counts are kept, never the requests or responses themselves, so logging doesn't add
		// to the memory used by events that make many requests or fetch large responses.
		$current->http_requests++;
		$args['_crontrol_logged'] = true;

		return $args;
	}

	/**
	 * Determines whether an HTTP response represents a failure.
	 *
	 * A response is considered a failure if it's a WP_Error or if its status code is 400 or above.
	 *
	 * @param mixed $response The HTTP response array or a WP_Error.
	 */
	private function is_http_failure( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		return intval( wp_remote_retrieve_response_code( $response ) ) >= 400;
	}

	/**
	 * Debugging action for the HTTP API.
	 *
	 * @param mixed   $response A parameter which varies depending on $action.
	 * @param string  $action   The debug action. Currently one of 'response' or 'transports_list'.
	 * @param string  $class    The HTTP transport class name.
	 * @param mixed[] $args     HTTP request arguments.
	 * @param string  $url      The request URL.
	 */
	public function action_http_api_debug( $response, $action, $class, $args, $url ): void {
		if ( 'response' !== $action ) {
			return;
		}

		$current = $this->active();

		if ( ! $current || empty( $args['_crontrol_logged'] ) ) {
			return;
		}

		if ( ! $this->is_http_failure( $response ) ) {
			$current->http_succeeded++;
		}

		$method = is_string( $args['method'] ?? null ) ? $args['method'] : 'GET';
		$safe   = self::redact_url( $url );

		if ( $this->is_http_failure( $response ) ) {
			if ( is_wp_error( $response ) ) {
				$message = sprintf(
					/* translators: 1: HTTP method, 2: Request URL, 3: Error message */
					__( 'HTTP %1$s request to %2$s failed: %3$s', 'wp-crontrol' ),
					$method,
					'{url}',
					'{error}'
				);
				$context = array(
					'url'   => $safe,
					'error' => $response->get_error_message(),
				);
			} else {
				$message = sprintf(
					/* translators: 1: HTTP method, 2: Request URL, 3: HTTP status code */
					__( 'HTTP %1$s request to %2$s returned status code %3$s', 'wp-crontrol' ),
					$method,
					'{url}',
					'{code}'
				);
				$context = array(
					'url'  => $safe,
					'code' => intval( wp_remote_retrieve_response_code( $response ) ),
				);
			}

			do_action( 'crontrol/error', $message, $context );
		} else {
			do_action(
				'crontrol/debug',
				sprintf(
					/* translators: 1: HTTP method, 2: Request URL, 3: HTTP status code */
					__( 'HTTP %1$s request to %2$s returned status code %3$s', 'wp-crontrol' ),
					$method,
					'{url}',
					'{code}'
				),
				array(
					'url'  => $safe,
					'code' => intval( wp_remote_retrieve_response_code( $response ) ),
				)
			);
		}
	}

	/**
	 * Counts emails sent during the event run.
	 *
	 * `wp_mail` is a filter, so the arguments must be returned unchanged.
	 *
	 * @param array<string, mixed> $args Email arguments.
	 * @return array<string, mixed> The same email arguments.
	 */
	public function filter_wp_mail( $args ) {
		$current = $this->active();

		if ( $current ) {
			$current->emails++;
		}

		return $args;
	}

	/**
	 * Counts emails that failed to send during the event run.
	 *
	 * @param mixed $error The error from wp_mail().
	 */
	public function action_wp_mail_failed( $error ): void {
		$current = $this->active();

		if ( ! $current ) {
			return;
		}

		$current->mail_failed++;

		if ( '' === $current->mail_error && $error instanceof \WP_Error ) {
			$current->mail_error = substr( $error->get_error_message(), 0, 300 );
		}
	}

	/**
	 * Logs an Action Scheduler queue item that was processed during the event run.
	 *
	 * Action Scheduler runs its queue via a single cron event (`action_scheduler_run_queue`) which
	 * processes a batch of its own queued actions. This records each successfully processed action so
	 * the individual queue items are visible in the event log.
	 *
	 * @param int    $action_id The Action Scheduler action ID.
	 * @param object $action    The action that was executed.
	 * @param string $context   Optional execution context.
	 */
	public function action_scheduler_after_execute( $action_id, $action = null, $context = '' ): void {
		if ( ! $this->active() ) {
			return;
		}

		$hook = ( is_object( $action ) && method_exists( $action, 'get_hook' ) ) ? (string) $action->get_hook() : '';

		if ( '' !== $hook ) {
			$message = sprintf(
				/* translators: 1: Action Scheduler action ID, 2: Hook name */
				__( 'Action Scheduler processed action %1$s (%2$s)', 'wp-crontrol' ),
				'{id}',
				'{hook}'
			);
			$context = array(
				'id'   => $action_id,
				'hook' => $hook,
			);
		} else {
			$message = sprintf(
				/* translators: %s: Action Scheduler action ID */
				__( 'Action Scheduler processed action %s', 'wp-crontrol' ),
				'{id}'
			);
			$context = array(
				'id' => $action_id,
			);
		}

		do_action( 'crontrol/debug', $message, $context );
	}

	/**
	 * Logs an Action Scheduler queue item whose execution failed during the event run.
	 *
	 * @param int       $action_id The Action Scheduler action ID.
	 * @param Throwable $error     The error that occurred.
	 * @param string    $context   Optional execution context.
	 */
	public function action_scheduler_failed_execution( $action_id, $error = null, $context = '' ): void {
		if ( ! $this->active() ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: Action Scheduler action ID, 2: Error message */
			__( 'Action Scheduler action %1$s failed: %2$s', 'wp-crontrol' ),
			'{id}',
			'{error}'
		);

		do_action( 'crontrol/error', $message, array(
			'id'    => $action_id,
			'error' => ( $error instanceof Throwable ) ? $error->getMessage() : '',
		) );
	}

	/**
	 * Exception handler.
	 *
	 * This will catch Throwable objects, which are fatals and uncaught exceptions.
	 * Every run still in progress is recorded as fatal, then the exception continues on its way.
	 *
	 * @param Throwable $e The error or exception.
	 * @throws Throwable Re-thrown when there is no previous handler.
	 */
	public function exception_handler( Throwable $e ): void {
		$old = $this->old_exception_handler;

		$this->finish_all( $e );

		if ( $old ) {
			call_user_func( $old, $e );
		} else {
			throw $e;
		}
	}

	/**
	 * Records any runs still in progress when the request ends, for example after a fatal error or an `exit`.
	 */
	public function shutdown_handler(): void {
		if ( ! $this->current() ) {
			return;
		}

		$e = error_get_last();
		$fatals = ( E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR );
		$exception = null;

		if ( $e && ( $e['type'] & $fatals ) ) {
			$exception = new \ErrorException(
				$e['message'],
				0,
				$e['type'],
				$e['file'],
				$e['line']
			);
		}

		$this->finish_all( $exception );
	}

	/**
	 * Finishes every run still in progress, innermost first.
	 *
	 * @param Throwable|null $exception The exception that ended them, if any.
	 */
	private function finish_all( ?Throwable $exception ): void {
		while ( $this->stack ) {
			$state = array_pop( $this->stack );

			if ( ! $state->ended ) {
				if ( $exception ) {
					$state->caught_exception = $exception;
				}
				$this->finish( $state );
			}
		}

		$this->stop_listening();
	}

	/**
	 * Adds the request-wide listeners when the outermost logged run starts.
	 */
	private function start_listening(): void {
		if ( $this->listening ) {
			return;
		}

		$this->listening = true;

		// The handler can still be on the stack from an earlier run, under one that an event added
		// and didn't remove. It's only installed once.
		if ( ! $this->handler_installed ) {
			$this->old_exception_handler = set_exception_handler( array( $this, 'exception_handler' ) );
			$this->handler_installed = true;
		}

		if ( ! $this->shutdown_registered ) {
			register_shutdown_function( array( $this, 'shutdown_handler' ) );
			$this->shutdown_registered = true;
		}

		add_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 9999, 2 );
		add_action( 'http_api_debug', array( $this, 'action_http_api_debug' ), 9999, 5 );
		add_filter( 'wp_mail', array( $this, 'filter_wp_mail' ), 9999 );
		add_action( 'wp_mail_failed', array( $this, 'action_wp_mail_failed' ), 9999 );
		add_action( 'action_scheduler_after_execute', array( $this, 'action_scheduler_after_execute' ), 9999, 3 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'action_scheduler_failed_execution' ), 9999, 3 );

		foreach ( Logger::get_levels() as $level ) {
			foreach ( array( 'crontrol', 'qm' ) as $prefix ) {
				$this->routes[ "{$prefix}/{$level}" ] = function ( $message, $context = array() ) use ( $level ): void {
					$this->route( $level, array( $message, is_array( $context ) ? $context : array() ) );
				};
			}
		}

		foreach ( array( 'crontrol', 'qm' ) as $prefix ) {
			$this->routes[ "{$prefix}/assert" ] = function ( $assertion, $message = '', $value = null ): void {
				$this->route( 'assert', array( $assertion, (string) $message, $value ) );
			};
			$this->routes[ "{$prefix}/log" ] = function ( $level, $message, $context = array() ): void {
				if ( in_array( $level, Logger::get_levels(), true ) ) {
					$this->route( (string) $level, array( $message, is_array( $context ) ? $context : array() ) );
				}
			};
		}

		foreach ( $this->routes as $hook => $callback ) {
			add_action( $hook, $callback, 10, 3 );
		}
	}

	/**
	 * Removes the request-wide listeners once no logged run is in progress.
	 */
	private function stop_listening(): void {
		if ( ! $this->listening ) {
			return;
		}

		$this->listening = false;

		remove_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 9999 );
		remove_action( 'http_api_debug', array( $this, 'action_http_api_debug' ), 9999 );
		remove_filter( 'wp_mail', array( $this, 'filter_wp_mail' ), 9999 );
		remove_action( 'wp_mail_failed', array( $this, 'action_wp_mail_failed' ), 9999 );
		remove_action( 'action_scheduler_after_execute', array( $this, 'action_scheduler_after_execute' ), 9999 );
		remove_action( 'action_scheduler_failed_execution', array( $this, 'action_scheduler_failed_execution' ), 9999 );

		foreach ( $this->routes as $hook => $callback ) {
			remove_action( $hook, $callback, 10 );
		}

		$this->routes = array();

		// Take this plugin's handler back off PHP's handler stack. If code in the event put its own
		// handler on top and left it there, leave the stack alone; this handler still passes
		// exceptions on to the one it replaced.
		if ( $this->handler_installed ) {
			$top = set_exception_handler( null );
			restore_exception_handler();

			if ( array( $this, 'exception_handler' ) === $top ) {
				restore_exception_handler();
				$this->old_exception_handler = null;
				$this->handler_installed = false;
			}
		}
	}

	/**
	 * Starts the logging for the current cron event.
	 *
	 * @param string       $hook      The hook name.
	 * @param mixed[]      $hook_args The arguments passed to the hook.
	 * @param int          $depth     The depth of the call stack for this invocation.
	 * @param list<string> $frames    The call sites that led to this invocation, outermost first.
	 */
	public function log_start( string $hook, array $hook_args = array(), int $depth = 0, array $frames = array() ): void {
		global $wpdb;

		if ( ! self::is_cron_context() ) {
			return;
		}

		// A run at this depth or deeper can't be a caller of this one, so it was left open by an
		// exception that was caught further up.
		$this->finish_abandoned( $depth - 1 );

		/**
		 * Filters whether the arguments of a cron event are stored in its log entry.
		 *
		 * Off by default because event arguments can contain personal data or secrets.
		 *
		 * @param bool   $store Whether to store the arguments.
		 * @param string $hook  The hook name.
		 */
		$store_args = (bool) apply_filters( 'crontrol/log_event_args', false, $hook );

		$crontrol_event = in_array( $hook, array( \Crontrol\Event\PHPCronEvent::HOOK_NAME, \Crontrol\Event\URLCronEvent::HOOK_NAME ), true );

		if ( $store_args && ! $crontrol_event ) {
			// Each argument is stored as a short description, never in full, so large arguments
			// can't use up memory or overflow the log.
			$args = array_map( array( Logger::class, 'describe' ), array_slice( array_values( $hook_args ), 0, 10 ) );
		} else {
			$args = $this->get_identifying_args( $hook, $hook_args );
		}

		$run = EventRun::start(
			$hook,
			$this->get_current_schedule(),
			// WordPress core currently doesn't provide a way to identify the scheduled time of a running event.
			time(),
			$args
		);

		try {
			EventRunModel::create( $run );
		} catch ( \RuntimeException $e ) {
			return;
		}

		$this->start_listening();

		$state = new LogRun();
		$state->run = $run;
		$state->hook = $hook;
		$state->depth = $depth;
		$state->frames = $frames;
		$state->logger = new Logger();
		$state->start_queries = (int) $wpdb->num_queries;

		$this->stack[] = $state;

		if ( defined( 'CAVALCADE_JOB_ID' ) ) {
			$message = sprintf(
				/* translators: %s: Job ID */
				__( 'Cavalcade job ID: %s', 'wp-crontrol' ),
				'{id}'
			);
			do_action( 'crontrol/debug', $message, array(
				'id' => constant( 'CAVALCADE_JOB_ID' ),
			) );
		}
	}

	/**
	 * Ends the logging for the current cron event.
	 *
	 * @param string $hook  The hook name.
	 * @param int    $depth The depth of the call stack for this invocation.
	 */
	public function log_end( string $hook, int $depth = 0 ): void {
		$index = null;

		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( $this->stack[ $i ]->hook === $hook && $this->stack[ $i ]->depth === $depth ) {
				$index = $i;
				break;
			}
		}

		if ( null !== $index ) {
			// Anything above this run was left open by an exception that was caught on the way back.
			$this->finish_abandoned( $depth );

			$state = array_pop( $this->stack );

			if ( $state && ! $state->ended ) {
				$this->finish( $state );
			}
		}

		if ( ! $this->current() ) {
			$this->stop_listening();
		}
	}

	/**
	 * Finishes the runs deeper than the given depth, which ended early because an exception was
	 * thrown in them and caught by the code that fired their hook.
	 *
	 * @param int $depth Runs deeper than this are finished.
	 */
	private function finish_abandoned( int $depth ): void {
		while ( $this->stack && end( $this->stack )->depth > $depth ) {
			$this->abandon( array_pop( $this->stack ) );
		}
	}

	/**
	 * Writes the final record for a run.
	 *
	 * @param LogRun $state   The run.
	 * @param bool   $errored Whether the run is known to have failed.
	 */
	private function finish( LogRun $state, bool $errored = false ): void {
		global $wpdb;

		$state->ended = true;

		$run = $state->run;

		$run->db_queries = max( 0, (int) $wpdb->num_queries - $state->start_queries );
		$run->https = $state->http_requests;
		$run->emails = $state->emails;
		$run->mail_failed = $state->mail_failed;

		if ( function_exists( 'memory_get_peak_usage' ) ) {
			$run->memory = memory_get_peak_usage( false );
		}

		// A request that failed, or never reported a response, counts as a warning.
		$has_http_warning = $state->http_succeeded < $state->http_requests;

		$has_warning = $errored || $state->logger->saw_warning();
		$has_error = $has_warning;

		foreach ( $state->logger->get_logs() as $log ) {
			$entry = array(
				'level'   => $log['level'],
				'message' => $log['message'],
			);

			if ( in_array( $log['level'], Logger::get_warning_levels(), true ) ) {
				$has_warning = true;
				$has_error = true;
				$run->errors[] = $entry;
				continue;
			}

			$run->logs[] = $entry;
		}

		if ( $state->logger->get_omitted() ) {
			$run->logs[] = array(
				'level'   => Logger::NOTICE,
				'message' => sprintf(
					/* translators: %s: Number of log messages */
					__( '%s more messages were not kept.', 'wp-crontrol' ),
					number_format_i18n( $state->logger->get_omitted() )
				),
			);
		}

		if ( $has_http_warning ) {
			$has_warning = true;
		}

		// An email that failed to send is a warning, like a failed HTTP request.
		if ( $state->mail_failed > 0 ) {
			$has_warning = true;
			$run->logs[] = array(
				'level'   => Logger::WARNING,
				'message' => sprintf(
					/* translators: 1: Number of emails, 2: Error message */
					_n( '%1$s email failed to send: %2$s', '%1$s emails failed to send: %2$s', $state->mail_failed, 'wp-crontrol' ),
					number_format_i18n( $state->mail_failed ),
					'' !== $state->mail_error ? $state->mail_error : __( 'no error message', 'wp-crontrol' )
				),
			);
		}

		$run->warning = $has_warning;
		$run->fatal = false;

		if ( null !== $state->caught_exception ) {
			$run->fatal = true;
			$run->errors[] = array(
				'level'   => Logger::CRITICAL,
				'message' => sprintf(
					'%s: %s in %s:%d',
					get_class( $state->caught_exception ),
					substr( $state->caught_exception->getMessage(), 0, 1000 ),
					basename( $state->caught_exception->getFile() ),
					$state->caught_exception->getLine()
				),
			);
			$run->mark_errored();
		} elseif ( $has_error ) {
			$run->mark_errored();
		} else {
			$run->mark_completed();
		}

		$run->errors = self::bound_entries( $run->errors );
		$run->logs = self::bound_entries( $run->logs );

		try {
			EventRunModel::update( $run );
		} catch ( \RuntimeException $e ) {
			// Keep the outcome and counters even if the details can't be saved.
			$run->logs = array();
			$run->errors = array(
				array(
					'level'   => Logger::WARNING,
					'message' => __( 'The details of this run could not be saved.', 'wp-crontrol' ),
				),
			);

			try {
				EventRunModel::update( $run );
			} catch ( \RuntimeException $e ) {
				return;
			}
		}

		/**
		 * Fires after a logged cron event run is finished and saved. Used by WP Crontrol's alerts.
		 *
		 * @internal
		 *
		 * @param EventRun $run The finished run.
		 */
		do_action( 'crontrol/run_finished', $run );
	}

	/**
	 * For WP Crontrol's own PHP and URL cron events, keeps just enough to tell them apart in the
	 * log: the display name, and the URL without its query string. Code, full URLs and integrity
	 * hashes are never stored.
	 *
	 * @param string  $hook      The hook name.
	 * @param mixed[] $hook_args The arguments passed to the hook.
	 * @return array<int, mixed>
	 */
	private function get_identifying_args( string $hook, array $hook_args ): array {
		if ( \Crontrol\Event\PHPCronEvent::HOOK_NAME !== $hook && \Crontrol\Event\URLCronEvent::HOOK_NAME !== $hook ) {
			return array();
		}

		$first = ( isset( $hook_args[0] ) && is_array( $hook_args[0] ) ) ? $hook_args[0] : array();
		$keep = array(
			'name' => isset( $first['name'] ) && is_string( $first['name'] ) ? $first['name'] : '',
		);

		if ( \Crontrol\Event\URLCronEvent::HOOK_NAME === $hook ) {
			$keep['url'] = self::redact_url( isset( $first['url'] ) && is_string( $first['url'] ) ? $first['url'] : '' );
			$keep['method'] = \Crontrol\Event\url_method( $first['method'] ?? 'GET' );
		}

		return array( $keep );
	}

	/**
	 * Keeps a list of log entries within what the database column can hold: long messages are
	 * shortened, and entries past the limit are replaced with a note saying how many were left out.
	 *
	 * @param array<int, array{level: string, message: string}> $entries Log entries.
	 * @return list<array{level: string, message: string}>
	 */
	public static function bound_entries( array $entries ): array {
		$max_bytes = 48000;
		$kept = array();
		$size = 2;

		foreach ( $entries as $i => $entry ) {
			$message = (string) $entry['message'];

			if ( strlen( $message ) > 1000 ) {
				$message = substr( $message, 0, 1000 ) . ' [truncated]';
			}

			$item = array(
				'level'   => (string) $entry['level'],
				'message' => $message,
			);
			$item_size = strlen( (string) wp_json_encode( $item ) ) + 1;

			if ( $size + $item_size > $max_bytes ) {
				$kept[] = array(
					'level'   => Logger::NOTICE,
					'message' => sprintf(
						/* translators: %s: Number of log entries */
						__( '%s more entries were not saved.', 'wp-crontrol' ),
						number_format_i18n( count( $entries ) - $i )
					),
				);
				break;
			}

			$kept[] = $item;
			$size += $item_size;
		}

		return $kept;
	}

	/**
	 * Gets the schedule name for the current event.
	 */
	private function get_current_schedule(): string {
		$hook = current_filter();
		$crons = _get_cron_array();

		foreach ( $crons as $timestamp => $cron ) {
			if ( isset( $cron[ $hook ] ) ) {
				foreach ( $cron[ $hook ] as $key => $event ) {
					return $event['schedule'] ?: '';
				}
			}
		}

		return '';
	}

	/**
	 * Returns an array of all known cron event hooks. Hooks that are logged have a value of true, all others false.
	 *
	 * @return array<string,bool> Array of logged status, keyed by hook name.
	 */
	public static function get_known_hooks(): array {
		$events = \Crontrol\Event\count_by_hook();
		$hooks = array_keys( $events );

		// Include every hook that has ever been seen, even if it's no longer scheduled.
		$all_hooks_ever_seen = get_option( 'crontrol_log_all_hooks', array() );
		if ( is_array( $all_hooks_ever_seen ) ) {
			$hooks = array_merge( $hooks, array_filter( $all_hooks_ever_seen, 'is_string' ) );
		}

		// Ensure Crontrol events are always present in the list of available events:
		$hooks[] = \Crontrol\Event\PHPCronEvent::HOOK_NAME;
		$hooks[] = \Crontrol\Event\URLCronEvent::HOOK_NAME;

		$enabled = self::get_enabled_hooks();

		$logged = array();

		foreach ( array_unique( $hooks ) as $hook ) {
			$logged[ $hook ] = in_array( $hook, $enabled, true );
		}

		ksort( $logged );

		return $logged;
	}

	/**
	 * Returns a human-readable schedule label for each currently scheduled hook.
	 *
	 * When the same hook is scheduled more than once, a recurring schedule is preferred over a
	 * non-recurring one. Hooks with no scheduled event are absent from the returned array.
	 *
	 * @return array<string,string> Schedule label keyed by hook name.
	 */
	public static function get_hook_schedules(): array {
		$labels = array();

		foreach ( \Crontrol\Schedule\get() as $schedule ) {
			$labels[ $schedule->name ] = ( '' !== $schedule->display ) ? $schedule->display : $schedule->name;
		}

		$non_recurring = __( 'Non-recurring', 'wp-crontrol' );
		$schedules = array();

		foreach ( \Crontrol\Event\get() as $event ) {
			$recurring = $event->is_recurring() && null !== $event->interval && $event->interval > 0;

			if ( $recurring && is_string( $event->schedule ) && isset( $labels[ $event->schedule ] ) ) {
				$label = $labels[ $event->schedule ];
			} else {
				$label = $non_recurring;
			}

			// Prefer a recurring schedule if the same hook is scheduled more than once.
			if ( ! isset( $schedules[ $event->hook ] ) || $non_recurring !== $label ) {
				$schedules[ $event->hook ] = $label;
			}
		}

		return $schedules;
	}

	/**
	 * Returns the list of hook names that logging is explicitly enabled for.
	 *
	 * @return array<int,string>
	 */
	public static function get_enabled_hooks(): array {
		// The setting is always saved autoloaded, so when it isn't among the autoloaded options it
		// hasn't been set. This avoids a database query on every request while logging is off.
		if ( ! array_key_exists( 'crontrol_log', wp_load_alloptions() ) ) {
			return array();
		}

		$enabled = get_option( 'crontrol_log', array() );

		if ( ! is_array( $enabled ) ) {
			return array();
		}

		return array_values( array_filter( array_filter( $enabled, 'is_string' ), array( self::class, 'is_loggable_hook' ) ) );
	}

	/**
	 * Whether a hook can be logged. WP Crontrol's own hooks never can: logging the hooks that the
	 * logger itself fires would log forever.
	 *
	 * @param string $hook The hook name.
	 */
	public static function is_loggable_hook( string $hook ): bool {
		return '' !== $hook && 0 !== strpos( $hook, 'crontrol/' );
	}

	public static function is_hook_logged( string $hook ): bool {
		return in_array( $hook, self::get_enabled_hooks(), true );
	}

	public static function set_logging_for_hook( string $hook, bool $log = true ): void {
		$logged = self::get_enabled_hooks();

		if ( $log ) {
			$logged[] = $hook;
		} else {
			$i = array_search( $hook, $logged, true );
			if ( false !== $i ) {
				unset( $logged[ $i ] );
			}
		}

		update_option( 'crontrol_log', array_values( array_unique( $logged ) ), true );
	}

	/**
	 * Replaces the entire list of hooks that logging is enabled for.
	 *
	 * @param array<int,string> $hooks List of hook names to enable logging for. Any hooks not
	 *                                  present in this list will have their logging disabled.
	 */
	public static function set_logged_hooks( array $hooks ): void {
		$hooks = array_values( array_unique( array_filter( $hooks, 'is_string' ) ) );

		update_option( 'crontrol_log', $hooks, true );
	}

	public static function has_errors(): bool {
		return EventRunModel::has_errors();
	}

	/**
	 * Returns the hooks of the currently scheduled events that come from plugins and themes,
	 * for the "Log all plugin hooks" control. WordPress core hooks, WP Crontrol's own maintenance
	 * hooks and events that recur every minute or more often are left out.
	 *
	 * @return array<int,string>
	 */
	public static function get_plugin_hooks(): array {
		$hooks = array();
		$core_hooks = \Crontrol\get_all_core_hooks();

		foreach ( \Crontrol\Event\get_core_cron_array() as $events ) {
			foreach ( $events as $hook => $instances ) {
				if ( in_array( $hook, INTERNAL_HOOKS, true ) || in_array( $hook, $core_hooks, true ) ) {
					continue;
				}

				foreach ( $instances as $instance ) {
					if ( isset( $instance['interval'] ) && $instance['interval'] <= MINUTE_IN_SECONDS ) {
						continue;
					}

					$hooks[ $hook ] = true;
				}
			}
		}

		return array_keys( $hooks );
	}

	/**
	 * Singleton instantiator.
	 *
	 * @return Log Logger instance.
	 */
	public static function get_instance(): self {
		static $instance;

		if ( ! isset( $instance ) ) {
			$instance = new Log();
		}

		return $instance;
	}

	/**
	 * Private class constructor. Use `get_instance()` to get the instance.
	 */
	private function __construct() {}
}
