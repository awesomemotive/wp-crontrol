<?php
// Minimal WP-CLI stubs for PHPStan, covering what the logs command uses.
// php-stubs/wp-cli-stubs conflicts with the WordPress stubs version this repo pins.

// phpcs:ignoreFile

namespace {
	class WP_CLI_Command {}

	class WP_CLI {
		/**
		 * @param string       $name
		 * @param callable|class-string|object $callable
		 * @param array<string,mixed> $args
		 */
		public static function add_command( $name, $callable, $args = array() ): bool { return true; }

		/** @param string $message */
		public static function line( $message = '' ): void {}

		/** @param string $message */
		public static function warning( $message ): void {}

		/** @param string $message */
		public static function success( $message ): void {}

		/**
		 * @param string|\WP_Error $message
		 * @return never
		 */
		public static function error( $message, bool $exit = true ) { exit; }

		/**
		 * @param string              $question
		 * @param array<string,mixed> $assoc_args
		 */
		public static function confirm( $question, $assoc_args = array() ): void {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                         $format
	 * @param array<int,array<string,mixed>> $items
	 * @param array<int,string>|string       $fields
	 */
	function format_items( $format, $items, $fields ): void {}
}
