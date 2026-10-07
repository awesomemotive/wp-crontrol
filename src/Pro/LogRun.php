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
	 * Collects the messages logged during the run.
	 */
	public Logger $logger;

	/**
	 * HTTP requests made during the event run, keyed by an internal request key.
	 * Only the method and whether the request failed are kept.
	 *
	 * @var array<string, array{method: string, failed: bool}>
	 */
	public array $https = array();

	/**
	 * Number of emails sent during the run.
	 */
	public int $emails = 0;

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
