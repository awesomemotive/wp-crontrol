<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Event\Event;
use Crontrol\Event\PHPCronEvent;
use Crontrol\Event\URLCronEvent;

class EventErrorTest extends Test {
	/**
	 * @covers \Crontrol\Event\PHPCronEvent::has_error
	 * @covers \Crontrol\Event\PHPCronEvent::integrity_failed
	 */
	public function testPHPEventWithSyntaxErrorIsMarkedAsHavingError(): void {
		$hook = PHPCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test PHP event with syntax error message in args
		$php = 'echo "test'; // Intentionally broken code
		$args = array(
			array(
				'code' => $php,
				'name' => 'Syntax Error Job',
				'hash' => wp_hash( $php ),
				'syntax_error_message' => 'Parse error: syntax error, unexpected end of file',
			),
		);

		$event = Event::create( $hook, $timestamp, 'syntax_sig', $args, null, null );
		self::assertTrue( $event->has_error(), 'PHP event with syntax_error_message should have error' );
		self::assertFalse( $event->integrity_failed(), 'PHP event with correct hash should not have integrity failure' );
	}

	/**
	 * @covers \Crontrol\Event\PHPCronEvent::has_error
	 * @covers \Crontrol\Event\PHPCronEvent::integrity_failed
	 */
	public function testPHPEventWithNoSyntaxErrorIsNotMarkedAsHavingError(): void {
		$hook = PHPCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test PHP event without syntax error
		$php = 'echo "valid code";';
		$args = array(
			array(
				'code' => $php,
				'name' => 'Valid Job',
				'hash' => wp_hash( $php ),
			),
		);

		$event = Event::create( $hook, $timestamp, 'valid_sig', $args, null, null );
		self::assertFalse( $event->has_error(), 'PHP event without syntax_error_message should not have error' );
		self::assertFalse( $event->integrity_failed(), 'PHP event with correct hash should not have integrity failure' );
	}

	/**
	 * Test has_error() method for PHP events with integrity failures
	 *
	 * @covers \Crontrol\Event\PHPCronEvent::has_error
	 * @covers \Crontrol\Event\PHPCronEvent::integrity_failed
	 */
	public function testPHPEventWithIntegrityFailureIsMarkedAsHavingError(): void {
		$hook = PHPCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test PHP event with integrity failure (also considered an error)
		$args = array(
			array(
				'code' => 'echo "test";',
				'name' => 'Integrity Fail Job',
				'hash' => 'wrong_hash',
			),
		);

		$event = Event::create( $hook, $timestamp, 'integrity_sig', $args, null, null );
		self::assertTrue( $event->has_error(), 'PHP event with integrity failure should have error' );
		self::assertTrue( $event->integrity_failed(), 'PHP event with wrong hash should have integrity failure' );
	}

	/**
	 * @covers \Crontrol\Event\URLCronEvent::has_error
	 */
	public function testURLEventWithValidURLIsNotMarkedAsHavingError(): void {
		$hook = URLCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test URL event with valid URL
		$url = 'https://example.com/webhook';
		$args = array(
			array(
				'url' => $url,
				'name' => 'Valid URL Job',
				'hash' => wp_hash( $url ),
			),
		);

		$event = Event::create( $hook, $timestamp, 'url_valid_sig', $args, null, null );
		self::assertFalse( $event->has_error(), 'URL event with valid URL should not have error' );
		self::assertFalse( $event->integrity_failed(), 'URL event with correct hash should not have integrity failure' );
	}

	/**
	 * @covers \Crontrol\Event\Event::create
	 * @covers \Crontrol\Event\URLCronEvent::has_error
	 */
	public function testURLEventWithLocalhostURL(): void {
		$hook = URLCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test URL event with potentially disallowed URL (localhost)
		$url = 'http://localhost:22';
		$args = array(
			array(
				'url' => $url,
				'name' => 'Localhost URL Job',
				'hash' => wp_hash( $url ),
			),
		);

		$event = Event::create( $hook, $timestamp, 'url_sig', $args, null, null );
		self::assertFalse( $event->has_error(), 'URL event with valid hash should not have error' );
		self::assertFalse( $event->integrity_failed(), 'URL event with correct hash should not have integrity failure' );
	}

	/**
	 * @covers \Crontrol\Event\Event::create
	 * @covers \Crontrol\Event\URLCronEvent::has_error
	 * @covers \Crontrol\Event\URLCronEvent::integrity_failed
	 */
	public function testURLEventWithIntegrityFailure(): void {
		$hook = URLCronEvent::HOOK_NAME;
		$timestamp = time() + 3600;

		// Test URL event with integrity failure
		$url = 'https://example.com/test';
		$args = array(
			array(
				'url' => $url,
				'name' => 'Integrity Fail Job',
				'hash' => 'wrong_hash',
			),
		);

		$event = Event::create( $hook, $timestamp, 'url_integrity_sig', $args, null, null );
		self::assertTrue( $event->has_error(), 'URL event with integrity failure should have error' );
		self::assertTrue( $event->integrity_failed(), 'URL event with wrong hash should have integrity failure' );
	}

	/**
	 * @covers \Crontrol\Event\PHPCronEvent::has_error
	 * @covers \Crontrol\Event\PHPCronEvent::integrity_failed
	 */
	public function testErrorDetectionWithBothSyntaxAndIntegrityFailures(): void {
		$timestamp = time() + 3600;

		// Test PHP event with both syntax error and integrity failure
		$args = array(
			array(
				'code' => 'echo "test";',
				'hash' => 'wrong_hash',
				'syntax_error_message' => 'Parse error',
			),
		);

		$event = Event::create(
			PHPCronEvent::HOOK_NAME,
			$timestamp,
			'both_errors_sig',
			$args,
			null,
			null
		);
		self::assertTrue( $event->has_error(), 'PHP event with both syntax and integrity errors should have error' );
		self::assertTrue( $event->integrity_failed(), 'PHP event with wrong hash should have integrity failure' );
	}
}
