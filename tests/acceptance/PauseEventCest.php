<?php
/**
 * Acceptance tests for pausing and resuming cron events.
 */

/**
 * Test class.
 */
class PauseEventCest {
	public function _before( AcceptanceTester $I ): void {
		$I->loginAsAdmin();
	}

	public function PausingAnEvent( AcceptanceTester $I ): void {
		$row = $I->amWorkingWithANewCronEvent( 'pause_me_soon' );

		$I->click( 'Pause', $row );
		$I->seeAdminSuccessNotice( 'Paused the pause_me_soon hook.' );
		$I->see( 'Paused', $row );

		$I->click( 'Paused events (1)' );
		$I->see( 'Paused', $row );
		$I->see( 'Edit', $row );
		$I->dontSee( 'Run now', $row );
		$I->see( 'Resume this hook', $row );
		$I->see( 'Delete', $row );

		$I->click( 'Resume', $row );
		$I->seeAdminSuccessNotice( 'Resumed the pause_me_soon hook.' );
		$I->see( 'Edit', $row );
		$I->see( 'Run now', $row );
		$I->see( 'Pause this hook', $row );
		$I->dontSee( 'Resume', $row );
		$I->see( 'Delete', $row );
	}
}
