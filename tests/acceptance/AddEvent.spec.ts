import { test, expect } from './utils/test-setup';

test.describe( 'Adding Cron Events', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Navigating to the Add Cron Event screen', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to navigate to the Add Cron Event screen'
		}
	}, async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
		await page.getByText( 'Add Cron Event' ).click();
		await expect( page.locator( 'h1' ) ).toContainText( 'Add Cron Event' );
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Add Cron Event' );
	} );

	test( 'Adding a new event', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to add a standard cron event'
		}
	}, async ( {
		page,
		admin,
		Crontrol,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
		await page.getByText( 'Add Cron Event' ).click();

		// Verify default state - PHP Code, URL, and HTTP Method fields should not be visible
		await expect( page.locator( '#crontrol_form th:has-text("PHP Code")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("URL")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("HTTP Method")' ) ).not.toBeVisible();

		// Standard cron event should be selected by default
		await expect( page.getByRole( 'radio', { name: 'Standard cron event' } ) ).toBeChecked();

		// Fill in hook name
		await page.locator( '#crontrol_hookname' ).fill( 'my_hookname' );

		// Add event
		await page.getByRole( 'button', { name: 'Add Event' } ).click();

		// Verify success
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Events' );
		await Crontrol.seeAdminSuccessNotice( 'Saved the cron event my_hookname.' );
	} );

	test( 'Adding a new URL event', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to add a URL cron event'
		}
	}, async ( {
		page,
		admin,
		Crontrol,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
		await page.getByText( 'Add Cron Event' ).click();

		// Initially should not see URL fields
		await expect( page.locator( '#crontrol_form th:has-text("PHP Code")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("URL")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("HTTP Method")' ) ).not.toBeVisible();

		// Select URL cron event
		await page.getByRole( 'radio', { name: 'URL cron event' } ).check();

		// Now URL and HTTP Method should be visible, but not PHP Code
		await expect( page.locator( '#crontrol_form th:has-text("PHP Code")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("URL")' ) ).toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("HTTP Method")' ) ).toBeVisible();

		// Fill in URL
		await page.locator( '#crontrol_url' ).fill( 'https://example.org/' );

		// Add event
		await page.getByRole( 'button', { name: 'Add Event' } ).click();

		// Verify success
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Events' );
		await Crontrol.seeAdminSuccessNotice( 'URL cron event saved.' );
		await expect( page.locator( '.crontrol-events' ) ).toContainText( 'https://example.org/' );
	} );

	test( 'Adding a new URL event with disallowed URL shows error', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I should see an error when adding a URL event with a disallowed URL'
		}
	}, async ( {
		page,
		admin,
		Crontrol,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
		await page.getByText( 'Add Cron Event' ).click();

		// Select URL cron event
		await page.getByRole( 'radio', { name: 'URL cron event' } ).check();

		// Fill in disallowed URL
		await page.locator( '#crontrol_url' ).fill( 'http://localhost:22' );

		// Add event
		await page.getByRole( 'button', { name: 'Add Event' } ).click();

		// Verify error
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Events' );
		await Crontrol.seeAdminErrorNotice( 'The cron event was saved but contains an error: The URL "http://localhost:22" is not allowed' );

		// Verify event exists but with limited actions
		const row = await Crontrol.amWorkingWithAnExistingCronEvent( 'http://localhost:22' );
		await expect( row.getByText( 'Edit' ) ).toBeVisible();
		await expect( row.getByText( 'Delete' ) ).toBeVisible();
		await expect( row.getByText( 'Run now' ) ).not.toBeVisible();
	} );

	test( 'Adding a new PHP event', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to add a PHP cron event'
		}
	}, async ( {
		page,
		admin,
		Crontrol,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=wp-crontrol' );
		await page.getByText( 'Add Cron Event' ).click();

		// Initially should not see PHP Code field
		await expect( page.locator( '#crontrol_form th:has-text("PHP Code")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("URL")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("HTTP Method")' ) ).not.toBeVisible();

		// Select PHP cron event
		await page.getByRole( 'radio', { name: 'PHP cron event' } ).check();

		// Now PHP Code should be visible, but not URL or HTTP Method
		await expect( page.locator( '#crontrol_form th:has-text("PHP Code")' ) ).toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("URL")' ) ).not.toBeVisible();
		await expect( page.locator( '#crontrol_form th:has-text("HTTP Method")' ) ).not.toBeVisible();

		// Fill in PHP code
		await Crontrol.fillPHPEditorField( 'amazing();' );

		// Add event
		await page.getByRole( 'button', { name: 'Add Event' } ).click();

		// Verify success
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Events' );
		await Crontrol.seeAdminSuccessNotice( 'PHP cron event saved.' );
		await expect( page.locator( '.crontrol-events' ) ).toContainText( 'amazing();' );
	} );
} );
