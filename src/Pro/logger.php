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
	 * Maximum number of messages kept per run. Later messages are counted but not kept.
	 */
	protected static int $max_entries = 200;

	/**
	 * Maximum length of a kept message, in bytes.
	 */
	protected static int $max_message_length = 1000;

	/**
	 * Array to store log messages.
	 *
	 * @var list<LogEntry>
	 */
	protected $logs = array();

	/**
	 * Number of messages that were not kept because the limit was reached.
	 */
	protected int $omitted = 0;

	/**
	 * Whether any message at warning level or above was logged, kept or not.
	 */
	protected bool $saw_warning = false;

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
		$message = self::shorten( $message );

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
		if ( in_array( $level, self::get_warning_levels(), true ) ) {
			$this->saw_warning = true;
		}

		if ( count( $this->logs ) >= self::$max_entries ) {
			$this->omitted++;
			return;
		}

		if ( $message instanceof WP_Error ) {
			$message = sprintf(
				'WP_Error: %s (%s)',
				self::shorten( (string) $message->get_error_message() ),
				self::shorten( (string) $message->get_error_code() )
			);
		}

		if ( $message instanceof Throwable ) {
			$message = sprintf(
				'%1$s: %2$s',
				get_class( $message ),
				self::shorten( $message->getMessage() )
			);
		}

		// Every part is shortened before anything else is done with it, so a large value can't use
		// up memory on its way to being truncated.
		if ( ! is_string( $message ) ) {
			$message = self::describe( $message );
		} else {
			$message = self::shorten( $message );

			if ( '' === trim( $message ) ) {
				$message = '(Empty string)';
			}
		}

		if ( null !== $prefix ) {
			$prefix = self::shorten( $prefix );
		}

		$message = self::interpolate( $message, $context, $prefix );

		if ( strlen( $message ) > self::$max_message_length ) {
			$message = substr( $message, 0, self::$max_message_length ) . ' [truncated]';
		}

		// Contexts are not kept: they can hold large values, and the message already includes them.
		$this->logs[] = array(
			'message' => $message,
			'level'   => $level,
		);
	}

	/**
	 * Number of messages that were not kept because the limit was reached.
	 */
	public function get_omitted(): int {
		return $this->omitted;
	}

	/**
	 * Whether any message at warning level or above was logged, kept or not.
	 */
	public function saw_warning(): bool {
		return $this->saw_warning;
	}

	/**
	 * Shortens a string to the maximum message length.
	 *
	 * @param string $text The text.
	 */
	protected static function shorten( string $text ): string {
		if ( strlen( $text ) <= self::$max_message_length ) {
			return $text;
		}

		return substr( $text, 0, self::$max_message_length ) . ' [truncated]';
	}

	/**
	 * Describes a value that isn't a string, without formatting all of it.
	 *
	 * Small arrays are shown in full; larger arrays and objects are summarised, so that logging a
	 * large value doesn't use up memory.
	 *
	 * @param mixed $value The value.
	 */
	public static function describe( $value ): string {
		if ( null === $value ) {
			return 'null';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return self::shorten( (string) $value );
		}

		if ( is_object( $value ) ) {
			return sprintf( '[%s]', get_class( $value ) );
		}

		if ( is_array( $value ) ) {
			$count = count( $value );
			$parts = array();
			$length = 0;

			foreach ( $value as $key => $item ) {
				$part = self::shorten( (string) $key ) . ' => ' . ( is_array( $item ) ? sprintf( 'array(%d)', count( $item ) ) : self::describe( $item ) );
				$length += strlen( $part );
				$parts[] = $part;

				if ( $length > self::$max_message_length || count( $parts ) >= 50 ) {
					break;
				}
			}

			$more = $count - count( $parts );

			return sprintf( 'array(%d) { %s%s }', $count, implode( ', ', $parts ), $more > 0 ? sprintf( ', … %d more', $more ) : '' );
		}

		return sprintf( '[%s]', gettype( $value ) );
	}

	/**
	 * @param string $message
	 * @param array<string, mixed> $context
	 * @param ?string $prefix
	 */
	protected static function interpolate( $message, array $context = array(), ?string $prefix = null ): string {
		// Only the placeholders that appear in the message are resolved, so a large context
		// costs nothing beyond what ends up in the message.
		$replace = array();

		if ( $context && preg_match_all( '/\{([^{}\s]+)\}/', $message, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $key ) {
				if ( ! array_key_exists( $key, $context ) ) {
					continue;
				}

				$val = $context[ $key ];

				if ( is_bool( $val ) ) {
					$replace[ "{{$key}}" ] = ( $val ? 'true' : 'false' );
				} elseif ( is_scalar( $val ) ) {
					$replace[ "{{$key}}" ] = self::shorten( (string) $val );
				} elseif ( is_object( $val ) ) {
					$replace[ "{{$key}}" ] = sprintf( '[%s]', get_class( $val ) );
				} else {
					$replace[ "{{$key}}" ] = sprintf( '[%s]', gettype( $val ) );
				}
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
