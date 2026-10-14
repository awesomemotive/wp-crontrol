<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Alerts;
use Crontrol\Pro\EventRun;
use Crontrol\Pro\EventRunModel;
use Crontrol\Pro\Log;
use WP_Error;

/**
 * Cron alerts, failed-email capture during logged runs, and the local referral record.
 */
final class AlertsTest extends Test {
	/**
	 * @var list<string>
	 */
	private array $observed = array();

	#[\Override]
	public function set_up() {
		parent::set_up();

		EventRunModel::truncate();
		delete_option( Alerts\SETTINGS_OPTION );
		delete_option( Alerts\STATE_OPTION );
		delete_option( \Crontrol\Promos\REFERRED_OPTION );

		$queue = &Alerts\queue();
		$queue = array();
	}

	#[\Override]
	public function tear_down() {
		foreach ( $this->observed as $hook ) {
			Log::get_instance()->unobserve( $hook );
		}

		remove_all_filters( 'crontrol/should_log' );
		remove_all_filters( 'pre_wp_mail' );
		EventRunModel::truncate();
		delete_option( Alerts\SETTINGS_OPTION );
		delete_option( Alerts\STATE_OPTION );
		wp_clear_scheduled_hook( Alerts\DIGEST_HOOK );

		$queue = &Alerts\queue();
		$queue = array();

		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $settings Alert settings.
	 */
	private function settings( array $settings ): void {
		update_option( Alerts\SETTINGS_OPTION, $settings, false );
	}

	private function failed_run( string $hook ): EventRun {
		$run           = EventRun::start( $hook, '', time(), array() );
		$run->status   = EventRun::STATUS_ERRORED;
		$run->errors[] = array(
			'level'   => 'error',
			'message' => 'Something broke',
		);

		return $run;
	}

	public function testNothingIsQueuedWhileAlertsAreOff(): void {
		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_hook' ) );

		self::assertSame( array(), Alerts\queue() );
	}

	public function testCompletedRunsDoNotAlert(): void {
		$this->settings( array( 'enabled' => true ) );

		$run         = EventRun::start( 'crontrol_alert_hook', '', time(), array() );
		$run->status = EventRun::STATUS_COMPLETED;
		Alerts\on_run_finished( $run );

		self::assertSame( array(), Alerts\queue() );
	}

	public function testAHookAlertsAtMostOnceAnHour(): void {
		$this->settings( array( 'enabled' => true ) );

		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_hook' ) );
		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_hook' ) );
		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_other' ) );

		self::assertCount( 2, Alerts\queue() );
		self::assertSame( array( 'crontrol_alert_hook', 'crontrol_alert_other' ), wp_list_pluck( Alerts\queue(), 'hook' ) );
	}

	public function testTheSiteHasAnHourlyCeiling(): void {
		$this->settings( array( 'enabled' => true ) );

		for ( $i = 0; $i < Alerts\SITE_LIMIT + 5; $i++ ) {
			Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_storm_' . $i ) );
		}

		self::assertCount( Alerts\SITE_LIMIT, Alerts\queue() );
	}

	public function testDigestModeCollectsFailures(): void {
		$this->settings( array( 'enabled' => true, 'mode' => 'digest' ) );

		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_hook' ) );
		Alerts\on_run_finished( $this->failed_run( 'crontrol_alert_hook' ) );

		self::assertSame( array(), Alerts\queue() );
		self::assertCount( 2, Alerts\get_state()['digest'] );

		add_filter( 'pre_wp_mail', '__return_true' );
		Alerts\send_digest();

		self::assertSame( array(), Alerts\get_state()['digest'] );
		self::assertTrue( Alerts\get_state()['last_result']['sent'] );
	}

	public function testAFailedSendIsRecordedWithItsReason(): void {
		$this->settings( array( 'enabled' => true ) );

		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' ) );
				return false;
			}
		);

		self::assertFalse( Alerts\send( 'Subject', 'Body', true ) );

		$last = Alerts\get_state()['last_result'];
		self::assertFalse( $last['sent'] );
		self::assertTrue( $last['test'] );
		self::assertSame( 'Could not instantiate mail function.', $last['error'] );
	}

	public function testRecipientsAreValidatedAndCapped(): void {
		$addresses = array( 'not an email' );

		for ( $i = 0; $i < 15; $i++ ) {
			$addresses[] = "person{$i}@example.com";
		}

		$this->settings(
			array(
				'enabled'    => true,
				'recipients' => implode( ', ', $addresses ),
			)
		);

		$recipients = Alerts\get_recipients();
		self::assertCount( 10, $recipients );
		self::assertNotContains( 'not an email', $recipients );
	}

	public function testRecipientsFallBackToTheAdminEmail(): void {
		$this->settings( array( 'enabled' => true ) );

		self::assertSame( array( get_option( 'admin_email' ) ), Alerts\get_recipients() );
	}

	public function testDigestScheduleFollowsTheSettings(): void {
		$this->settings( array( 'enabled' => true, 'mode' => 'digest' ) );
		Alerts\sync_digest_schedule();
		self::assertIsInt( wp_next_scheduled( Alerts\DIGEST_HOOK ) );

		$this->settings( array( 'enabled' => false ) );
		Alerts\sync_digest_schedule();
		self::assertFalse( wp_next_scheduled( Alerts\DIGEST_HOOK ) );
	}

	public function testFailedEmailsAreCountedOnTheRun(): void {
		add_filter( 'crontrol/should_log', '__return_true' );
		Log::get_instance()->observe( 'crontrol_alert_mail_hook' );
		$this->observed[] = 'crontrol_alert_mail_hook';

		add_action(
			'crontrol_alert_mail_hook',
			static function (): void {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );
			}
		);

		do_action( 'crontrol_alert_mail_hook' );

		$runs = array_values(
			array_filter(
				EventRunModel::query()->per_page( 10 )->get(),
				static fn ( EventRun $run ): bool => 'crontrol_alert_mail_hook' === $run->hook
			)
		);

		self::assertCount( 1, $runs );
		self::assertSame( 1, $runs[0]->mail_failed );
		self::assertTrue( $runs[0]->warning );
		self::assertStringContainsString( 'SMTP connect() failed.', (string) wp_json_encode( $runs[0]->logs ) );

		// Outside a logged run, a failed email isn't counted anywhere.
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Elsewhere' ) );
		self::assertCount( 1, EventRunModel::query()->per_page( 10 )->get() );
	}

	public function testReferralsAreRecordedOnceAndLocally(): void {
		\Crontrol\Promos\record_referral( 'wp-mail-smtp' );
		$first = get_option( \Crontrol\Promos\REFERRED_OPTION );

		\Crontrol\Promos\record_referral( 'wp-mail-smtp' );

		self::assertSame( $first, get_option( \Crontrol\Promos\REFERRED_OPTION ) );
		self::assertIsArray( $first );
		self::assertArrayHasKey( 'wp-mail-smtp', $first );

		$autoload = wp_load_alloptions();
		self::assertArrayNotHasKey( \Crontrol\Promos\REFERRED_OPTION, $autoload );
	}
}
