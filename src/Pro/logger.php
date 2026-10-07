<?php
/**
 * Class for logging extra data to crontrol event logs.
 *
 * @package wp-crontrol
 */

namespace Crontrol\Pro;

use Exception;
use InvalidArgumentException;
use Throwable;
use WP_Error;

/**
 * PSR-3 compatible logger which encapsulates the extra logging functionality during events.
 *
 * @phpstan-type LogEntry array{
 *   message: string,
 *   context: array<string, mixed>,
 *   level: string,
 * }
 * @phpstan-type LogMessage WP_Error|Throwable|string|bool|null
 */
class Logger {

	public const EMERGENCY = 'emergency';
	public const ALERT = 'alert';
	public const CRITICAL = 'critical';
	public const ERROR = 'error';
	public const WARNING = 'warning';
	public const NOTICE = 'notice';
	public const INFO = 'info';
	public const DEBUG = 'debug';

	/**
	 * Array to store log messages.
	 *
	 * @var list<LogEntry>
	 */
	protected $logs = array();

	public function init(): void {
		foreach ( self::get_levels() as $level ) {
			add_action( "crontrol/{$level}", array( $this, $level ), 10, 2 );
			add_action( "qm/{$level}", array( $this, $level ), 10, 2 );
		}

		add_action( 'crontrol/assert', array( $this, 'assert' ), 10, 3 );
		add_action( 'crontrol/log', array( $this, 'log' ), 10, 3 );
		add_action( 'qm/assert', array( $this, 'assert' ), 10, 3 );
		add_action( 'qm/log', array( $this, 'log' ), 10, 3 );
	}

	/**
	 * Stops collecting log messages, so that messages go to the run that is in progress.
	 */
	public function deinit(): void {
		foreach ( self::get_levels() as $level ) {
			remove_action( "crontrol/{$level}", array( $this, $level ), 10 );
			remove_action( "qm/{$level}", array( $this, $level ), 10 );
		}

		remove_action( 'crontrol/assert', array( $this, 'assert' ), 10 );
		remove_action( 'crontrol/log', array( $this, 'log' ), 10 );
		remove_action( 'qm/assert', array( $this, 'assert' ), 10 );
		remove_action( 'qm/log', array( $this, 'log' ), 10 );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function emergency( $message, array $context = array() ): void {
		$this->store( self::EMERGENCY, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function alert( $message, array $context = array() ): void {
		$this->store( self::ALERT, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function critical( $message, array $context = array() ): void {
		$this->store( self::CRITICAL, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function error( $message, array $context = array() ): void {
		$this->store( self::ERROR, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function warning( $message, array $context = array() ): void {
		$this->store( self::WARNING, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function notice( $message, array $context = array() ): void {
		$this->store( self::NOTICE, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function info( $message, array $context = array() ): void {
		$this->store( self::INFO, $message, $context );
	}

	/**
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param LogMessage $message
	 */
	public function debug( $message, array $context = array() ): void {
		$this->store( self::DEBUG, $message, $context );
	}

	/**
	 * @param mixed $assertion
	 * @param string $message
	 * @param ?mixed $value
	 */
	public function assert( $assertion, string $message = '', $value = null ): void {
		$prefix = null;

		if ( $assertion ) {
			$level = self::DEBUG;

			if ( $message ) {
				$message = sprintf(
					/* translators: %s: Assertion message */
					__( 'Assertion passed: %s', 'wp-crontrol' ),
					$message
				);
			} else {
				$message = __( 'Assertion passed', 'wp-crontrol' );
			}
		} else {
			$level = self::ERROR;

			if ( $message ) {
				$message = sprintf(
					/* translators: %s: Assertion message */
					__( 'Assertion failed: %s', 'wp-crontrol' ),
					$message
				);
			} else {
				$message = __( 'Assertion failed', 'wp-crontrol' );
			}

			if ( $value !== null ) {
				$prefix = $message;
				$message = $value;
			}
		}

		$this->store( $level, $message, array(), $prefix );
	}

	/**
	 * @return list<LogEntry>
	 */
	public function get_logs(): array {
		return $this->logs;
	}

	/**
	 * @param string $level
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @phpstan-param self::* $level
	 * @phpstan-param LogMessage $message
	 * @throws InvalidArgumentException If log level is not supported.
	 */
	public function log( $level, $message, array $context = array() ): void {
		if ( ! in_array( $level, self::get_levels(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported log level' );
		}

		$this->store( $level, $message, $context );
	}

	/**
	 * @param string $level
	 * @param mixed $message
	 * @param array<string, mixed> $context
	 * @param ?string $prefix
	 * @phpstan-param self::* $level
	 * @phpstan-param LogMessage $message
	 */
	protected function store( $level, $message, array $context = array(), ?string $prefix = null ): void {
		if ( $message instanceof WP_Error ) {
			$message = sprintf(
				'WP_Error: %s (%s)',
				$message->get_error_message(),
				$message->get_error_code()
			);
		}

		if ( $message instanceof Throwable ) {
			$message = sprintf(
				'%1$s: %2$s',
				get_class( $message ),
				$message->getMessage()
			);
		}

		if ( ! is_string( $message ) ) {
			if ( null === $message ) {
				$message = 'null';
			} elseif ( false === $message ) {
				$message = 'false';
			} elseif ( true === $message ) {
				$message = 'true';
			}

			$message = print_r( $message, true );
		} elseif ( '' === trim( $message ) ) {
			$message = '(Empty string)';
		}

		$this->logs[] = array(
			'message' => self::interpolate( $message, $context, $prefix ),
			'context' => $context,
			'level'   => $level,
		);
	}

	/**
	 * @param string $message
	 * @param array<string, mixed> $context
	 * @param ?string $prefix
	 */
	protected static function interpolate( $message, array $context = array(), ?string $prefix = null ): string {
		// build a replacement array with braces around the context keys
		$replace = array();

		foreach ( $context as $key => $val ) {
			// check that the value can be casted to string
			if ( is_bool( $val ) ) {
				$replace[ "{{$key}}" ] = ( $val ? 'true' : 'false' );
			} elseif ( is_scalar( $val ) ) {
				$replace[ "{{$key}}" ] = $val;
			} elseif ( is_object( $val ) ) {
				$replace[ "{{$key}}" ] = sprintf( '[%s]', get_class( $val ) );
			} else {
				$replace[ "{{$key}}" ] = sprintf( '[%s]', gettype( $val ) );
			}
		}

		// interpolate replacement values into the message and return
		$message = strtr( $message, $replace );

		if ( $prefix !== null ) {
			$message = $prefix . "\n" . $message;
		}

		return $message;
	}

	/**
	 * @return array<int, string>
	 * @phpstan-return list<self::*>
	 */
	public static function get_levels(): array {
		return array(
			self::EMERGENCY,
			self::ALERT,
			self::CRITICAL,
			self::ERROR,
			self::WARNING,
			self::NOTICE,
			self::INFO,
			self::DEBUG,
		);
	}

	/**
	 * @return array<int, string>
	 * @phpstan-return list<self::*>
	 */
	public static function get_warning_levels(): array {
		return array(
			self::EMERGENCY,
			self::ALERT,
			self::CRITICAL,
			self::ERROR,
			self::WARNING,
		);
	}
}
