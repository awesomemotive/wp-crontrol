<?php
/**
 * Acceptance testing actor.
 */

use Codeception\Util\Locator;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 */
class AcceptanceTester extends \Codeception\Actor {
	use _generated\AcceptanceTesterActions;

	/**
	 * Checks that the current page contains an admin success notice.
	 *
	 * @param string $text The message text to search for.
	 */
	public function seeAdminSuccessNotice( string $text ) {
		return $this->see( $text, '.notice-success' );
	}

	/**
	 * Checks that the current page contains an admin success notice.
	 *
	 * @param string $text The message text to search for.
	 */
	public function seeAdminWarningNotice( string $text ) {
		return $this->see( $text, '.notice-warning' );
	}

	/**
	 * Checks that the current page contains an admin success notice.
	 *
	 * @param string $text The message text to search for.
	 */
	public function seeAdminErrorNotice( string $text ) {
		return $this->see( $text, '.notice-error' );
	}

	/**
	 * Checks that the current page contains an admin success notice.
	 *
	 * @param string $text The message text to search for.
	 */
	public function seeAdminInfoNotice( string $text ) {
		return $this->see( $text, '.notice-info' );
	}

	/**
	 * Fill out lines of code in the PHP editor field.
	 *
	 * @example
	 *
	 * ```php
	 * $I->fillPHPEditorField("if ( function_exists( 'foo' ) {", "\tfoo();", "}");
	 * ```
	 *
	 * @param string ...$values Individual lines to fill in.
	 * @return void
	 */
	public function fillPHPEditorField( string ...$values ) {
		$this->executeJS( 'document.getElementsByClassName("CodeMirror")[0].CodeMirror.setValue(Array.prototype.join.call(arguments, "\n"));', $values );
	}

	/**
	 * Go to the cron event listing page in the admininstration area of the site.
	 *
	 * @return string The admin page path.
	 */
	public function amOnCronEventListingPage() {
		return $this->amOnAdminPage( 'tools.php?page=wp-crontrol' );
	}

	/**
	 * Go to the cron schedule listing page in the admininstration area of the site.
	 *
	 * @return string The admin page path.
	 */
	public function amOnCronScheduleListingPage() {
		return $this->amOnAdminPage( 'options-general.php?page=wp-crontrol-schedules' );
	}

	/**
	 * Create a cron event to work with.
	 *
	 * @param string $hook_name The event hook name.
	 * @param string $args     The event arguments encoded as JSON.
	 * @return string
	 */
	public function amWorkingWithANewCronEvent( string $hook_name, string $args = '' ) {
		$this->amOnCronEventListingPage();
		$this->click( 'Add New Cron Event', '#wpbody' );
		$this->fillField( 'Hook Name', $hook_name );
		$this->fillField( 'Arguments (optional)', $args );
		$this->selectOption( 'input[name="crontrol_next_run_date_local"]', 'Tomorrow' );
		$this->click( 'Add Event' );

		return Locator::contains( '.crontrol-events tr', $hook_name );
	}

	/**
	 * Work with an existing cron event.
	 *
	 * @param string $hook_name The event hook name.
	 * @return string
	 */
	public function amWorkingWithAnExistingCronEvent( string $hook_name ) {
		$this->amOnCronEventListingPage();

		return Locator::contains( '.crontrol-events tr', $hook_name );
	}
}
