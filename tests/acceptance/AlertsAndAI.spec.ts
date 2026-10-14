import { test, expect } from './utils/test-setup.js';

test.describe( 'Cron Alerts and Cron AI', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Turning on cron alerts', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to get an email when a logged cron event fails'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await page.goto( '/wp-admin/tools.php?page=wp-crontrol-alerts' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Alerts' );
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Alerts' );

		await page.getByLabel( 'Email me when a logged cron event fails' ).check();
		await page.getByLabel( 'One summary a day' ).check();
		await page.getByRole( 'button', { name: 'Save Alert Settings' } ).click();

		await Crontrol.seeAdminSuccessNotice( 'Alert settings saved.' );
		await expect( page.getByLabel( 'Email me when a logged cron event fails' ) ).toBeChecked();
		await expect( page.getByLabel( 'One summary a day' ) ).toBeChecked();
	} );

	test( 'Viewing the Cron AI screen', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to see what an AI assistant can do with my cron events'
		}
	}, async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/tools.php?page=wp-crontrol-ai' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron AI' );
		await expect( page.getByText( 'wp-crontrol/list-events' ) ).toBeVisible();
		await expect( page.getByText( 'wp-crontrol/run-event' ) ).toBeVisible();
	} );
} );
