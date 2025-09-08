<?php
/**
 * Acceptance tests for listing cron events.
 */

/**
 * Test class.
 */
class ListEventsCest {
	public function _before( AcceptanceTester $I ): void {
		$I->loginAsAdmin();
	}

	public function ListCronEvents( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->see( 'Cron Events', 'h1' );
		$I->see( 'Cron Events', '#crontrol-header' );
		$I->seeElement( 'table.crontrol-events' );
	}
}
