<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use function Crontrol\time_element;

/**
 * @covers \Crontrol\time_element
 */
class TimeElementTest extends Test {
	#[\Override]
	public function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'UTC' );
		update_option( 'gmt_offset', 0 );
	}

	#[\Override]
	public function tearDown(): void {
		remove_all_filters( 'gettext_with_context' );
		$GLOBALS['wp_locale']->init();
		parent::tearDown();
	}

	public function testOtherDatesUseTheDefaultEnglishFormat(): void {
		$timestamp = (int) gmmktime( 15, 5, 0, 1, 15, 2035 );

		self::assertStringContainsString( '>January 15th at 3:05 pm</time>', time_element( $timestamp ) );
	}

	public function testTodayKeepsItsWording(): void {
		$timestamp = (int) strtotime( 'today 12:00 UTC' );

		self::assertStringContainsString( '>Today at 12:00 pm</time>', time_element( $timestamp ) );
	}

	/**
	 * Month names come from the site's locale, so they are translated (support forum request).
	 */
	public function testMonthNamesAreLocalized(): void {
		$GLOBALS['wp_locale']->month['01'] = 'janvier';
		$timestamp = (int) gmmktime( 15, 5, 0, 1, 15, 2035 );

		self::assertStringContainsString( 'janvier', time_element( $timestamp ) );
		self::assertStringNotContainsString( 'January', time_element( $timestamp ) );
	}

	/**
	 * The date and time formats are translatable (support forum request).
	 */
	public function testFormatsAreTranslatable(): void {
		add_filter(
			'gettext_with_context',
			static function ( $translation, $text, $context, $domain ) {
				if ( 'wp-crontrol' !== $domain ) {
					return $translation;
				}
				if ( 'cron event time format' === $context ) {
					return 'H:i';
				}
				if ( 'cron event date format' === $context ) {
					return 'j F';
				}
				return $translation;
			},
			10,
			4
		);

		$timestamp = (int) gmmktime( 15, 5, 0, 1, 15, 2035 );

		self::assertStringContainsString( '>15 January at 15:05</time>', time_element( $timestamp ) );
	}

	public function testOffsetMarkerShowsWhenTheOffsetDiffersFromNow(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$now_offset = wp_date( 'P' );
		$year       = (int) gmdate( 'Y' ) + 1;
		$timestamp  = (int) gmmktime( 17, 0, 0, 1, 15, $year );

		if ( wp_date( 'P', $timestamp ) === $now_offset ) {
			$timestamp = (int) gmmktime( 17, 0, 0, 7, 15, $year );
		}

		self::assertMatchesRegularExpression( '/\([+-]\d{2}:\d{2}\)<\/time>$/', time_element( $timestamp ) );
	}
}
