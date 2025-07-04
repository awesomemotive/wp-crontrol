<?php
/**
 * Acceptance tests for deleting all cron events with a given hook name.
 */

/**
 * Test class.
 */
class DeleteAllWithHookCest {
	public function _before( AcceptanceTester $I ) {
		$I->loginAsAdmin();
	}

	public function DeletingAHook( AcceptanceTester $I ) {
		$I->amWorkingWithACronEvent( 'example_hook', '[1]' );
		$I->amWorkingWithACronEvent( 'example_hook', '[2]' );
		$row = $I->amWorkingWithACronEvent( 'example_hook', '[3]' );

		$I->click( 'Delete all events with this hook (3)', $row );
		$I->acceptPopup();
		$I->seeAdminSuccessNotice( 'Deleted all example_hook cron events.' );

		$I->dontSee( 'example_hook', '.crontrol-events' );
	}

	public function DeletingAPersistentWordPressCoreHook( AcceptanceTester $I ) {
		$I->amWorkingWithACronEvent( 'wp_scheduled_delete', '[1]' );
		$I->amWorkingWithACronEvent( 'wp_scheduled_delete', '[2]' );
		$row = $I->amWorkingWithACronEvent( 'wp_scheduled_delete', '[3]' );

		$I->click( 'Delete all events with this hook (4)', $row );
		$I->acceptPopup();
		$I->seeAdminSuccessNotice( 'Deleted all wp_scheduled_delete cron events.' );
	}
}
