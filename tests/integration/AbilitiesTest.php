<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;
use Crontrol\Pro\Log;
use WP_Ability;
use WP_Error;

/**
 * The abilities must follow the same permission rules as WP Crontrol's admin screens.
 */
final class AbilitiesTest extends Test {
	private const HOOK = 'crontrol_ability_test_hook';

	#[\Override]
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9 or later.' );
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK, array( 'one' ) );
		delete_option( \Crontrol\PAUSED_OPTION );
		delete_option( 'crontrol_log' );
	}

	#[\Override]
	public function tear_down() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( PHPCronEvent::HOOK_NAME );
		delete_option( \Crontrol\PAUSED_OPTION );
		delete_option( 'crontrol_log' );
		remove_all_filters( 'user_has_cap' );
		remove_all_filters( 'map_meta_cap' );

		parent::tear_down();
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		self::assertInstanceOf( WP_Ability::class, $ability, $name );

		return $ability;
	}

	private function admin(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $user );

		if ( is_multisite() ) {
			grant_super_admin( $user );
		}

		wp_set_current_user( $user );
	}

	private function without_cap( string $cap ): void {
		add_filter(
			'user_has_cap',
			static function ( array $allcaps ) use ( $cap ): array {
				$allcaps[ $cap ] = false;
				return $allcaps;
			}
		);

		// Super admins on multisite skip user_has_cap, so deny the capability outright.
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( $cap ): array {
				return $requested === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
	}

	private function php_event(): string {
		$code = 'error_log( "ability test" );';
		$args = array(
			array(
				'code' => $code,
				'name' => 'Ability test',
				'hash' => wp_hash( $code ),
			),
		);
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, PHPCronEvent::HOOK_NAME, $args );

		return md5( serialize( $args ) );
	}

	public function testAbilitiesAreRegistered(): void {
		foreach ( array_keys( \Crontrol\Abilities\get_definitions() ) as $name ) {
			$this->ability( $name );
		}
	}

	public function testSubscriberCanUseNoAbilities(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::assertIsInt( $user );
		wp_set_current_user( $user );

		$inputs = array(
			'wp-crontrol/list-events'      => array(),
			'wp-crontrol/list-schedules'   => array(),
			'wp-crontrol/list-logs'        => array(),
			'wp-crontrol/get-insights'     => array(),
			'wp-crontrol/get-log'          => array( 'id' => 1 ),
			'wp-crontrol/get-event'        => array( 'hook' => self::HOOK, 'sig' => md5( serialize( array( 'one' ) ) ) ),
			'wp-crontrol/run-event'        => array( 'hook' => self::HOOK, 'sig' => md5( serialize( array( 'one' ) ) ) ),
			'wp-crontrol/pause-hook'       => array( 'hook' => self::HOOK ),
			'wp-crontrol/resume-hook'      => array( 'hook' => self::HOOK ),
			'wp-crontrol/set-hook-logging' => array( 'hook' => self::HOOK, 'enabled' => true ),
		);

		foreach ( $inputs as $name => $input ) {
			$result = $this->ability( $name )->execute( $input );
			self::assertWPError( $result, $name );
		}

		self::assertSame( array(), Log::get_enabled_hooks() );
		self::assertFalse( get_option( \Crontrol\PAUSED_OPTION, false ) );
	}

	public function testListEventsNeverIncludesPHPCode(): void {
		$this->admin();
		$this->php_event();

		$result = $this->ability( 'wp-crontrol/list-events' )->execute( array( 'type' => 'php' ) );

		self::assertIsArray( $result );
		self::assertSame( 1, $result['total'] );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'error_log', $encoded );
		self::assertStringNotContainsString( wp_hash( 'error_log( "ability test" );' ), $encoded );
		self::assertSame( 'Ability test', $result['events'][0]['name'] );
	}

	public function testRunningPHPEventsNeedsEditFiles(): void {
		$this->admin();
		$sig = $this->php_event();
		$this->without_cap( 'edit_files' );

		$result = $this->ability( 'wp-crontrol/run-event' )->check_permissions(
			array(
				'hook' => PHPCronEvent::HOOK_NAME,
				'sig'  => $sig,
			)
		);

		self::assertFalse( $result );
	}

	public function testRunningEventsWithFailedIntegrityIsRefused(): void {
		$this->admin();

		$code = 'error_log( "tampered" );';
		$args = array(
			array(
				'code' => $code,
				'name' => '',
				// A hash for a URL event can never validate PHP code.
				'hash' => \Crontrol\Event\url_hash( $code ),
			),
		);
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, PHPCronEvent::HOOK_NAME, $args );

		$result = $this->ability( 'wp-crontrol/run-event' )->execute(
			array(
				'hook' => PHPCronEvent::HOOK_NAME,
				'sig'  => md5( serialize( $args ) ),
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'crontrol_event_not_runnable', $result->get_error_code() );
	}

	public function testCrontrolHooksCannotBePaused(): void {
		$this->admin();

		foreach ( array( PHPCronEvent::HOOK_NAME, URLCronEvent::HOOK_NAME ) as $hook ) {
			$result = $this->ability( 'wp-crontrol/pause-hook' )->execute( array( 'hook' => $hook ) );
			self::assertWPError( $result, $hook );
		}
	}

	public function testArbitraryActionsCannotBePaused(): void {
		$this->admin();

		$result = $this->ability( 'wp-crontrol/pause-hook' )->execute( array( 'hook' => 'init' ) );

		self::assertWPError( $result );
		self::assertFalse( get_option( \Crontrol\PAUSED_OPTION, false ) );
	}

	public function testPauseAndResumeAScheduledHook(): void {
		$this->admin();

		$paused = $this->ability( 'wp-crontrol/pause-hook' )->execute( array( 'hook' => self::HOOK ) );
		self::assertIsArray( $paused );
		self::assertArrayHasKey( self::HOOK, (array) get_option( \Crontrol\PAUSED_OPTION ) );

		// Resuming works even once the event is gone, as long as the hook is paused.
		wp_clear_scheduled_hook( self::HOOK );
		$resumed = $this->ability( 'wp-crontrol/resume-hook' )->execute( array( 'hook' => self::HOOK ) );
		self::assertIsArray( $resumed );
		self::assertArrayNotHasKey( self::HOOK, (array) get_option( \Crontrol\PAUSED_OPTION ) );
	}

	public function testMaintenanceHooksCannotBeLogged(): void {
		$this->admin();

		$result = $this->ability( 'wp-crontrol/set-hook-logging' )->execute(
			array(
				'hook'    => 'crontrol/maintenance',
				'enabled' => true,
			)
		);

		self::assertWPError( $result );
		self::assertSame( array(), Log::get_enabled_hooks() );
	}

	public function testLoggingCanBeToggled(): void {
		$this->admin();

		$on = $this->ability( 'wp-crontrol/set-hook-logging' )->execute(
			array(
				'hook'    => self::HOOK,
				'enabled' => true,
			)
		);
		self::assertIsArray( $on );
		self::assertTrue( $on['logged'] );

		$off = $this->ability( 'wp-crontrol/set-hook-logging' )->execute(
			array(
				'hook'    => self::HOOK,
				'enabled' => false,
			)
		);
		self::assertIsArray( $off );
		self::assertFalse( $off['logged'] );
	}

	public function testGetLogReportsUnknownIDs(): void {
		$this->admin();

		self::assertWPError( $this->ability( 'wp-crontrol/get-log' )->execute( array( 'id' => 999999 ) ) );
	}

	public function testListSchedulesReturnsCoreSchedules(): void {
		$this->admin();

		$result = $this->ability( 'wp-crontrol/list-schedules' )->execute();

		self::assertIsArray( $result );
		self::assertContains( 'hourly', wp_list_pluck( $result['schedules'], 'name' ) );
	}

	public function testReadonlyAnnotations(): void {
		self::assertTrue( $this->ability( 'wp-crontrol/list-events' )->get_meta()['annotations']['readonly'] );
		self::assertTrue( $this->ability( 'wp-crontrol/run-event' )->get_meta()['annotations']['destructive'] );
		self::assertFalse( $this->ability( 'wp-crontrol/run-event' )->get_meta()['annotations']['readonly'] );
	}
}
