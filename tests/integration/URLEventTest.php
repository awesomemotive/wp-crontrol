<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Exception\MissingURLException;
use Crontrol\Exception\MissingHashException;
use Crontrol\Exception\InvalidHashException;
use Crontrol\Exception\InvalidURLException;
use Crontrol\Exception\UnexpectedHTTPCodeException;

use function Crontrol\handle_url_cron_event;

class URLEventTest extends Test {
	public function testMissingURLTriggersException(): void {
		$this->expectException( MissingURLException::class );

		$url = '';
		$hash = null;

		handle_url_cron_event( $url, 'GET', $hash );
	}

	public function testMissingHashTriggersException(): void {
		$this->expectException( MissingHashException::class );

		$url = 'http://example.com';
		$hash = null;

		handle_url_cron_event( $url, 'GET', $hash );
	}

	public function testInvalidHashTriggersException(): void {
		$this->expectException( InvalidHashException::class );

		$url = 'http://example.com';
		$hash = 'invalidhash';

		handle_url_cron_event( $url, 'GET', $hash );
	}

	public function testInvalidURLTriggersException(): void {
		$this->expectException( InvalidURLException::class );

		$url = 'http://localhost:22';
		$hash = wp_hash( $url );

		handle_url_cron_event( $url, 'GET', $hash );
	}

	public function test404ResponseTriggersException(): void {
		$this->expectException( UnexpectedHTTPCodeException::class );

		$url = 'http://httpbin/status/404';
		$hash = wp_hash( $url );

		handle_url_cron_event( $url, 'GET', $hash );
	}

	public function testSuccessfulRequestWorksAsExpected(): void {
		$url = 'http://httpbin/status/200';
		$hash = wp_hash( $url );

		/**
		 * @param array|WP_Error $response    HTTP response or WP_Error object.
		 * @param string         $context     Context under which the hook is fired.
		 * @param string         $class       HTTP transport used.
		 * @param array          $parsed_args HTTP request arguments.
		 * @param string         $url         The request URL.
		 */
		add_action( 'http_api_debug', function( $response, $context, $class, $args, $url_called ) use ( $url ) {
			self::assertSame( $url, $url_called );
			self::assertNotWPError( $response );
		}, 10, 5 );

		handle_url_cron_event( $url, 'GET', $hash );
	}
}
