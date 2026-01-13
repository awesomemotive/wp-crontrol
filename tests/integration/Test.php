<?php declare(strict_types = 1);

namespace Crontrol\Tests;

abstract class Test extends \WP_UnitTestCase {
	/**
	 * Runs the routine before each test is executed.
	 */
	#[\Override]
	public function set_up() {
		parent::set_up();

		/**
		 * Allow local httpbin requests to pass through.
		 *
		 * @param bool   $external Whether HTTP request is external.
		 * @param string $host     Host name of the requested URL.
		 * @param string $url      Requested URL.
		 */
		add_filter( 'http_request_host_is_external', static function( bool $external, string $host, string $url ): bool {
			// Allow httpbin requests.
			if ( 'httpbin' === $host ) {
				return true;
			}

			return $external;
		}, 10, 3 );
	}
}
