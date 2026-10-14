<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Alerts;
use Crontrol\Pro\EventRun;

/**
 * Alerts queued for another site in the same request go to that site's recipients.
 *
 * @group ms-required
 */
final class AlertsMultisiteTest extends Test {
	#[\Override]
	public function tear_down() {
		remove_all_filters( 'pre_wp_mail' );

		$queue = &Alerts\queue();
		$queue = array();

		parent::tear_down();
	}

	public function testAlertsGoToTheSiteTheEventFailedOn(): void {
		$site_two = self::factory()->blog->create();
		self::assertIsInt( $site_two );

		update_option( Alerts\SETTINGS_OPTION, array( 'enabled' => true, 'recipients' => 'one@example.com' ), false );

		switch_to_blog( $site_two );
		update_option( Alerts\SETTINGS_OPTION, array( 'enabled' => true, 'recipients' => 'two@example.com' ), false );

		$run           = EventRun::start( 'crontrol_alert_site_two', '', time(), array() );
		$run->status   = EventRun::STATUS_ERRORED;
		$run->errors[] = array(
			'level'   => 'error',
			'message' => 'Site two broke',
		);
		Alerts\on_run_finished( $run );
		restore_current_blog();

		$sent = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, array $atts ) use ( &$sent ) {
				$sent[] = array(
					'to'   => (array) $atts['to'],
					'site' => get_current_blog_id(),
				);
				return true;
			},
			10,
			2
		);

		Alerts\flush_queue();

		self::assertCount( 1, $sent );
		self::assertSame( array( 'two@example.com' ), $sent[0]['to'] );
		self::assertSame( $site_two, $sent[0]['site'] );

		// The result is stored on site two, and site one is untouched.
		$site_one_result = Alerts\get_state()['last_result'];
		switch_to_blog( $site_two );
		$site_two_result = Alerts\get_state()['last_result'];
		restore_current_blog();

		self::assertSame( array(), $site_one_result );
		self::assertArrayHasKey( 'sent', $site_two_result );
		self::assertTrue( $site_two_result['sent'] );
	}
}
