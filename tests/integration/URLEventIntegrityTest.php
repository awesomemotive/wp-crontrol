<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;

use function Crontrol\Event\check_integrity;
use function Crontrol\Event\check_url_integrity;
use function Crontrol\Event\url_hash;
use function Crontrol\Event\url_method;

class URLEventIntegrityTest extends Test {
	/**
	 * A hash created for a URL cron event must never pass the integrity check for PHP code.
	 *
	 * @covers \Crontrol\Event\url_hash
	 * @covers \Crontrol\Event\check_integrity
	 */
	public function testURLHashDoesNotValidatePHPCode(): void {
		$value = 'error_log( "from a url hash" );';

		self::assertFalse( check_integrity( $value, url_hash( $value ) ) );

		$event = Event::create(
			PHPCronEvent::HOOK_NAME,
			time() + 3600,
			'url_hash_as_php',
			array(
				array(
					'code' => $value,
					'name' => '',
					'hash' => url_hash( $value ),
				),
			),
			null,
			null
		);

		self::assertTrue( $event->integrity_failed() );
	}

	/**
	 * New URL events use the prefixed hash; events saved before 1.22.0 keep working.
	 *
	 * @covers \Crontrol\Event\check_url_integrity
	 * @covers \Crontrol\Event\URLCronEvent::integrity_failed
	 */
	public function testURLIntegrityAcceptsCurrentAndLegacyHashes(): void {
		$url = 'https://example.com/cron';

		self::assertTrue( check_url_integrity( $url, url_hash( $url ) ) );
		self::assertTrue( check_url_integrity( $url, wp_hash( $url ) ) );
		self::assertFalse( check_url_integrity( $url, url_hash( 'https://example.com/other' ) ) );
		self::assertFalse( check_url_integrity( $url, '' ) );
		self::assertFalse( check_url_integrity( '', url_hash( '' ) ) );

		foreach ( array( url_hash( $url ), wp_hash( $url ) ) as $hash ) {
			$event = Event::create(
				URLCronEvent::HOOK_NAME,
				time() + 3600,
				'url_integrity',
				array(
					array(
						'url'    => $url,
						'method' => 'GET',
						'name'   => '',
						'hash'   => $hash,
					),
				),
				null,
				null
			);

			self::assertFalse( $event->integrity_failed() );
		}
	}

	/**
	 * @covers \Crontrol\Event\url_method
	 */
	public function testURLMethodIsLimitedToTheFormOptions(): void {
		self::assertSame( 'POST', url_method( 'post' ) );
		self::assertSame( 'HEAD', url_method( 'HEAD' ) );
		self::assertSame( 'DELETE', url_method( 'DELETE' ) );
		self::assertSame( 'GET', url_method( 'GET' ) );
		self::assertSame( 'GET', url_method( "GET\r\nX-Injected: 1" ) );
		self::assertSame( 'GET', url_method( 'PUT' ) );
		self::assertSame( 'GET', url_method( null ) );
		self::assertSame( 'GET', url_method( array( 'POST' ) ) );
	}
}
