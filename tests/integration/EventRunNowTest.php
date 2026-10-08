<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use function Crontrol\Event\force_schedule_single_event;

class EventRunNowTest extends Test {
	public function testForceScheduleSingleEventSchedulesImmediateEvent(): void {
		$result = force_schedule_single_event( 'crontrol_test_run_now', array( 'a' ) );

		self::assertTrue( $result );
		self::assertIsObject( wp_get_scheduled_event( 'crontrol_test_run_now', array( 'a' ), 1 ) );
	}

	public function testForceScheduleSingleEventSucceedsWhenImmediateEventAlreadyExists(): void {
		force_schedule_single_event( 'crontrol_test_run_now' );

		// The cron array is unchanged by the second call, so `update_option()` returns false.
		$result = force_schedule_single_event( 'crontrol_test_run_now' );

		self::assertTrue( $result );
		self::assertIsObject( wp_get_scheduled_event( 'crontrol_test_run_now', array(), 1 ) );
	}

	public function testForceScheduleSingleEventSucceedsWithCustomCronStorage(): void {
		$this->use_custom_cron_storage();

		$result = force_schedule_single_event( 'crontrol_test_run_now' );

		self::assertTrue( $result );
		self::assertIsObject( wp_get_scheduled_event( 'crontrol_test_run_now', array(), 1 ) );
	}

	public function testForceScheduleSingleEventFailsWhenEventIsNotSaved(): void {
		// Discards the new cron array, as a storage plugin that rejects the event would.
		add_filter(
			'pre_update_option_cron',
			/**
			 * @param mixed $value
			 * @param mixed $old_value
			 * @return mixed
			 */
			static fn( $value, $old_value ) => $old_value,
			10,
			2
		);

		$result = force_schedule_single_event( 'crontrol_test_run_now' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'could_not_add', $result->get_error_code() );
		self::assertFalse( wp_get_scheduled_event( 'crontrol_test_run_now', array(), 1 ) );
	}

	/**
	 * Emulates a plugin such as Cavalcade or Cron Control that stores events outside the `cron` option.
	 *
	 * The plugin saves the new cron array from the `pre_update_option_cron` filter and returns the old
	 * value so the option itself is never updated, which makes `update_option()` return false. It
	 * answers reads of the `cron` option from its own storage.
	 */
	private function use_custom_cron_storage(): void {
		$stored = null;

		add_filter(
			'pre_update_option_cron',
			/**
			 * @param mixed $value
			 * @param mixed $old_value
			 * @return mixed
			 */
			static function ( $value, $old_value ) use ( &$stored ) {
				$stored = $value;

				return $old_value;
			},
			10,
			2
		);

		add_filter(
			'pre_option_cron',
			/**
			 * @param mixed $pre
			 * @return mixed
			 */
			static function ( $pre ) use ( &$stored ) {
				return $stored ?? $pre;
			}
		);
	}
}
