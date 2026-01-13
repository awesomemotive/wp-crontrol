import { Page, expect } from '@playwright/test';
import { GlobalUtils } from '@johnbillion/plugin-infrastructure/acceptance';

// Simple admin utility interface to match what we need
interface Admin {
	visitAdminPage( path?: string, queryString?: string ): Promise<void>;
}

export class CrontrolUtils {
	private page: Page;
	private admin: Admin;
	private globalUtils: GlobalUtils;

	constructor( page: Page, admin: Admin, globalUtils: GlobalUtils ) {
		this.page = page;
		this.admin = admin;
		this.globalUtils = globalUtils;
	}

	/**
	 * Login via the wp-login.php page
	 */
	async loginViaPage( username: string, password: string ) {
		await this.page.goto( '/wp-login.php' );
		await this.page.fill( 'input[name="log"]', username );
		await this.page.fill( 'input[name="pwd"]', password );
		await this.page.locator( '#wp-submit' ).click();

		// @todo verify we're logged in (can't use HTTP status code as WP returns 200 even on failed login)
	}

	/**
	 * Check for admin success notice
	 */
	async seeAdminSuccessNotice( text: string ) {
		await expect( this.page.locator( '.notice-success' ) ).toContainText( text );
	}

	/**
	 * Check for admin warning notice
	 */
	async seeAdminWarningNotice( text: string ) {
		await expect( this.page.locator( '.notice-warning' ) ).toContainText( text );
	}

	/**
	 * Check for admin error notice
	 */
	async seeAdminErrorNotice( text: string ) {
		await expect( this.page.locator( '.notice-error' ) ).toContainText( text );
	}

	/**
	 * Check for admin info notice
	 */
	async seeAdminInfoNotice( text: string ) {
		await expect( this.page.locator( '.notice-info' ) ).toContainText( text );
	}

	/**
	 * Create a user with the specified username, role, and optional custom data
	 */
	createUser( username: string, role: string, customData: { email?: string; name?: string; locale?: string } = {} ) {
		const email = customData.email || `${username}@example.com`;
		const displayName = customData.name || username;

		this.globalUtils.runWPCLICommand( `user create ${username} ${email} --role=${role} --display_name="${displayName}" --user_pass=password` );

		// Set user locale if provided
		if ( customData.locale ) {
			this.globalUtils.runWPCLICommand( `user meta update ${username} locale ${customData.locale}` );
		}
	}

	/**
	 * Fill out lines of code in the PHP editor field.
	 */
	async fillPHPEditorField( ...values: string[] ) {
		await this.page.evaluate( ( lines ) => {
			const editor = document.getElementsByClassName( 'CodeMirror' )[0] as any;
			if ( editor && editor.CodeMirror ) {
				editor.CodeMirror.setValue( lines.join( '\n' ) );
			}
		}, values );
	}

	/**
	 * Go to the cron event listing page in the administration area of the site.
	 */
	async amOnCronEventListingPage() {
		await this.admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
	}

	/**
	 * Go to the cron schedule listing page in the administration area of the site.
	 */
	async amOnCronScheduleListingPage() {
		await this.admin.visitAdminPage( 'options-general.php', 'page=wp-crontrol-schedules' );
	}

	/**
	 * Create a cron event to work with.
	 */
	async amWorkingWithANewCronEvent( hookName: string, args: string = '' ) {
		await this.amOnCronEventListingPage();
		await this.page.getByText( 'Add Cron Event' ).click();

		// Wait for the form to be visible
		await this.page.waitForSelector( '#crontrol_hookname', { timeout: 5000 } );

		// Fill in the hook name using the correct ID
		await this.page.locator( '#crontrol_hookname' ).fill( hookName );

		// Fill in arguments if provided
		if ( args ) {
			await this.page.getByLabel( 'Arguments (optional)' ).fill( args );
		}

		// Select "Tomorrow" radio button for Next Run (value is "+1 day")
		await this.page.locator( 'input[name="crontrol_next_run_date_local"][value="+1 day"]' ).check();

		// Click Add Event button
		await this.page.getByRole( 'button', { name: 'Add Event' } ).click();

		// Return a locator for the row containing the event
		return this.page.locator( '.crontrol-events tr' ).filter( { hasText: hookName } );
	}

	/**
	 * Work with an existing cron event.
	 */
	async amWorkingWithAnExistingCronEvent( hookName: string ) {
		await this.amOnCronEventListingPage();

		// Return a locator for the row containing the event
		return this.page.locator( '.crontrol-events tr' ).filter( { hasText: hookName } );
	}
}
