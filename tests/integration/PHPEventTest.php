<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Exception\MissingHashException;
use Crontrol\Exception\InvalidHashException;

use function Crontrol\handle_php_cron_event;

class PHPEventTest extends Test {
	public function testMissingHashTriggersException(): void {
		$this->expectException( MissingHashException::class );

		$code = 'echo "Hello, World!";';

		handle_php_cron_event( $code, null );
	}

	public function testInvalidHashTriggersException(): void {
		$this->expectException( InvalidHashException::class );

		$code = 'echo "Hello, World!";';
		$hash = 'invalidhash';

		handle_php_cron_event( $code, $hash );
	}

	public function testValidPHPIsExecuted(): void {
		$code = 'echo "Hello, World!";';
		$hash = wp_hash( $code );

		ob_start();

		handle_php_cron_event( $code, $hash );

		$output = ob_get_clean();

		self::assertSame( 'Hello, World!', $output );
	}
}
