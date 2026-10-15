<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Pro\Log;

/**
 * @group ms-required
 */
final class LoggingMultisiteTest extends Test {
	public function testJobsAreScheduledOnASubsiteThatLogs(): void {
		$site_id = self::factory()->blog->create();
		self::assertIsInt( $site_id );

		switch_to_blog( $site_id );

		set_current_screen( 'dashboard' );
		Log::set_logging_for_hook( 'some_plugin_hook', true );

		\Crontrol\Pro\on_init();

		self::assertNotFalse( wp_next_scheduled( 'crontrol/maintenance' ) );
		self::assertNotFalse( wp_next_scheduled( 'crontrol/metrics' ) );

		restore_current_blog();
		set_current_screen( 'front' );
	}
}
