import { test, expect } from './utils/test-setup.js';

test.describe( 'Suggestions from the WP Crontrol team', () => {
	test.beforeEach( async ( { globalUtils, Crontrol } ) => {
		// A fresh install for each test, so no dismissal carries over.
		globalUtils.installWordPress();
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Dismissing the panel hides it on every Cron screen', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to dismiss the new-home panel for good'
		}
	}, async ( {
		page,
	} ) => {
		const panel = page.locator( '[data-crontrol-promo="welcome"]' );

		await page.goto( '/wp-admin/tools.php?page=wp-crontrol-logs' );
		await expect( panel ).toBeVisible();

		const saved = page.waitForResponse( ( response ) => response.url().includes( 'crontrol_dismiss_background' ) );
		await panel.getByRole( 'link', { name: 'Dismiss' } ).click();
		await saved;
		await expect( panel ).toHaveCount( 0 );

		await page.reload();
		await expect( panel ).toHaveCount( 0 );

		await page.goto( '/wp-admin/tools.php?page=wp-crontrol' );
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Events' );
		await expect( panel ).toHaveCount( 0 );
	} );

	test( 'A dismissal that fails to save brings the panel back', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to know when a dismissal did not save'
		}
	}, async ( {
		page,
	} ) => {
		const panel = page.locator( '[data-crontrol-promo="welcome"]' );

		await page.route( /crontrol_dismiss_background/, ( route ) => route.fulfill( { status: 403, body: 'The link you followed has expired.' } ) );

		await page.goto( '/wp-admin/tools.php?page=wp-crontrol' );
		await panel.getByRole( 'link', { name: 'Dismiss' } ).click();

		await expect( panel ).toBeVisible();
		await expect( panel ).toContainText( 'Could not dismiss this. Try again' );

		await page.unroute( /crontrol_dismiss_background/ );
		await page.reload();
		await expect( panel ).toBeVisible();
	} );

	test( 'The panel stays until the dismissal is saved', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need a dismissal to only look done once it is saved'
		}
	}, async ( {
		page,
	} ) => {
		const panel = page.locator( '[data-crontrol-promo="welcome"]' );
		let release: () => void = () => {};
		const held = new Promise<void>( ( resolve ) => {
			release = resolve;
		} );

		await page.route( /crontrol_dismiss_background/, async ( route ) => {
			await held;
			await route.continue();
		} );

		await page.goto( '/wp-admin/tools.php?page=wp-crontrol' );
		await panel.getByRole( 'link', { name: 'Dismiss' } ).click();
		await expect( panel ).toBeVisible();

		release();
		await expect( panel ).toHaveCount( 0 );
	} );
} );
