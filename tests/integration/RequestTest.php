<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Request;

class RequestTest extends Test {
	public function testInitPopulatesAllKnownProperties(): void {
		$props = [
			'args'                            => '[]',
			'next_run_date_local'             => '2024-01-15 10:00:00',
			'next_run_date_local_custom_date' => '2024-01-15',
			'next_run_date_local_custom_time' => '10:00:00',
			'schedule'                        => 'weekly',
			'hookname'                        => 'test_hook',
			'hookcode'                        => 'echo "test";',
			'eventname'                       => 'Test Event',
			'url'                             => 'https://example.com',
			'method'                          => 'GET',
			'original_hookname'               => 'original_test_hook',
			'original_sig'                    => 'abc123',
			'original_next_run_utc'           => '1705312800',
		];

		$request = Request::init( $props );

		self::assertSame( '[]', $request->args );
		self::assertSame( '2024-01-15 10:00:00', $request->next_run_date_local );
		self::assertSame( '2024-01-15', $request->next_run_date_local_custom_date );
		self::assertSame( '10:00:00', $request->next_run_date_local_custom_time );
		self::assertSame( 'weekly', $request->schedule );
		self::assertSame( 'test_hook', $request->hookname );
		self::assertSame( 'echo "test";', $request->hookcode );
		self::assertSame( 'Test Event', $request->eventname );
		self::assertSame( 'https://example.com', $request->url );
		self::assertSame( 'GET', $request->method );
		self::assertSame( 'original_test_hook', $request->original_hookname );
		self::assertSame( 'abc123', $request->original_sig );
		self::assertSame( '1705312800', $request->original_next_run_utc );
	}

	public function testInitStripsCrontrolPrefixFromPropertyNames(): void {
		$props = [
			'crontrol_args'     => '["test"]',
			'crontrol_hookname' => 'prefixed_hook',
			'crontrol_schedule' => 'daily',
		];

		$request = Request::init( $props );

		self::assertSame( '["test"]', $request->args );
		self::assertSame( 'prefixed_hook', $request->hookname );
		self::assertSame( 'daily', $request->schedule );
	}
}
