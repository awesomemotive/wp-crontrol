<?php
/**
 * Acceptance tests for adding cron events.
 */

/**
 * Test class.
 */
class AddEventCest {
	public function _before( AcceptanceTester $I ): void {
		$I->loginAsAdmin();
	}

	public function NavigatingToTheAddCronEventScreen( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->click( 'Add New Cron Event', '#wpbody' );
		$I->see( 'Add Cron Event', 'h1' );
		$I->see( 'Add Cron Event', '#crontrol-header' );
	}

	public function AddingANewEvent( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->click( 'Add New Cron Event', '#wpbody' );
		$I->dontSee( 'PHP Code', '#crontrol_form th' );
		$I->dontSee( 'URL', '#crontrol_form th' );
		$I->dontSee( 'HTTP Method', '#crontrol_form th' );
		$I->seeOptionIsSelected( 'input[name="crontrol_action"]', 'Standard cron event' );
		$I->fillField( 'Hook Name', 'my_hookname' );
		$I->click( 'Add Event' );
		$I->see( 'Cron Events', 'h1' );
		$I->seeAdminSuccessNotice( 'Saved the cron event my_hookname.' );
	}

	public function AddingANewURLEvent( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->click( 'Add New Cron Event', '#wpbody' );
		$I->dontSee( 'PHP Code', '#crontrol_form th' );
		$I->dontSee( 'URL', '#crontrol_form th' );
		$I->dontSee( 'HTTP Method', '#crontrol_form th' );
		$I->selectOption( 'input[name="crontrol_action"]', 'URL cron event' );
		$I->dontSee( 'PHP Code', '#crontrol_form th' );
		$I->see( 'URL', '#crontrol_form th' );
		$I->see( 'HTTP Method', '#crontrol_form th' );
		$I->fillField( '#crontrol_url', 'https://example.org/' );
		$I->click( 'Add Event' );
		$I->see( 'Cron Events', 'h1' );
		$I->seeAdminSuccessNotice( 'URL cron event saved.' );
		$I->see( 'https://example.org/' );
	}

	public function AddingANewURLEventWithDisallowedURLShowsError( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->click( 'Add New Cron Event', '#wpbody' );
		$I->selectOption( 'input[name="crontrol_action"]', 'URL cron event' );
		$I->fillField( '#crontrol_url', 'http://localhost:22' );
		$I->click( 'Add Event' );
		$I->see( 'Cron Events', 'h1' );
		$I->seeAdminErrorNotice( 'The cron event was saved but contains an error: The URL "http://localhost:22" is not allowed' );

		$row = $I->amWorkingWithAnExistingCronEvent( 'http://localhost:22' );
		$I->see( 'Edit', $row );
		$I->see( 'Delete', $row );
		$I->dontSee( 'Run now', $row );
	}

	public function AddingANewPHPEvent( AcceptanceTester $I ): void {
		$I->amOnCronEventListingPage();
		$I->click( 'Add New Cron Event', '#wpbody' );
		$I->dontSee( 'PHP Code', '#crontrol_form th' );
		$I->dontSee( 'URL', '#crontrol_form th' );
		$I->dontSee( 'HTTP Method', '#crontrol_form th' );
		$I->selectOption( 'input[name="crontrol_action"]', 'PHP cron event' );
		$I->see( 'PHP Code', '#crontrol_form th' );
		$I->dontSee( 'URL', '#crontrol_form th' );
		$I->dontSee( 'HTTP Method', '#crontrol_form th' );
		$I->fillPHPEditorField( 'amazing();' );
		$I->click( 'Add Event' );
		$I->see( 'Cron Events', 'h1' );
		$I->seeAdminSuccessNotice( 'PHP cron event saved.' );
		$I->see( 'amazing();' );
	}
}
