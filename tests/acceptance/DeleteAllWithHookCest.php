<?php
/**
 * Acceptance tests for deleting all cron events with a given hook name.
 */

/**
 * Test class.
 */
class DeleteAllWithHookCest {
	public function _before( AcceptanceTester $I ): void {
		$I->loginAsAdmin();
	}

	public function DeletingAHook( AcceptanceTester $I ): void {
		$I->amWorkingWithANewCronEvent( 'example_hook', '[1]' );
		$I->amWorkingWithANewCronEvent( 'example_hook', '[2]' );
		$row = $I->amWorkingWithANewCronEvent( 'example_hook', '[3]' );

		$I->click( 'Delete all events with this hook (3)', $row );
		$I->acceptPopup();
		$I->seeAdminSuccessNotice( 'Deleted all example_hook cron events.' );

		$I->dontSee( 'example_hook', '.crontrol-events' );
	}

	public function DeletingAPersistentWordPressCoreHook( AcceptanceTester $I ): void {
		$I->amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[1]' );
		$I->amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[2]' );
		$row = $I->amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[3]' );

		$I->click( 'Delete all events with this hook (4)', $row );
		$I->acceptPopup();
		$I->seeAdminSuccessNotice( 'Deleted all wp_scheduled_delete cron events.' );
	}
}
