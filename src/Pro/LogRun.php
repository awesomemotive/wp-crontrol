<?php
/**
 * State for one logged event run.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Pro;

use Throwable;

/**
 * State for one logged event run.
 *
 * Runs are kept on a stack so that a logged hook which fires inside another logged hook gets its
 * own record, and nothing from one run leaks into the next.
 */
final class LogRun {
	/**
	 * The record being written.
	 */
	public EventRun $run;

	/**
	 * The hook name.
	 */
	public string $hook = '';

	/**
	 * The depth of the call stack when the hook fired, which identifies this invocation.
	 */
	public int $depth = 0;

	/**
	 * The call sites that led to this invocation, outermost first. While they're still on the
	 * call stack, the run is in progress.
	 *
	 * @var list<string>
	 */
	public array $frames = array();

	/**
	 * Collects the messages logged during the run.
	 */
	public Logger $logger;

	/**
	 * Number of HTTP requests made during the run.
	 */
	public int $http_requests = 0;

	/**
	 * Number of those requests that returned a response with a status below 400.
	 */
	public int $http_succeeded = 0;

	/**
	 * Number of emails sent during the run.
	 */
	public int $emails = 0;

	/**
	 * Number of emails that failed to send during the run.
	 */
	public int $mail_failed = 0;

	/**
	 * The first email failure message, if any.
	 */
	public string $mail_error = '';

	/**
	 * Number of database queries when the run started.
	 */
	public int $start_queries = 0;

	/**
	 * The fatal error or uncaught exception that ended the run, if any.
	 */
	public ?Throwable $caught_exception = null;

	/**
	 * Whether the run has been written.
	 */
	public bool $ended = false;
}
