import { test, expect } from './utils/test-setup';

test.describe( 'Pausing and Resuming Cron Events', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Pausing an event', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to pause and resume cron events'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		const row = await Crontrol.amWorkingWithANewCronEvent( 'pause_me_soon' );

		await row.getByText( 'Pause' ).click();
		await Crontrol.seeAdminSuccessNotice( 'Paused the pause_me_soon hook.' );
		await expect( row ).toContainText( 'Paused' );

		await page.getByText( 'Paused events (1)' ).click();
		await expect( row ).toContainText( 'Paused' );
		await expect( row.getByText( 'Edit' ) ).toBeVisible();
		await expect( row.getByText( 'Run now' ) ).not.toBeVisible();
		await expect( row.getByText( 'Resume this hook' ) ).toBeVisible();
		await expect( row.getByText( 'Delete' ) ).toBeVisible();

		await row.getByText( 'Resume' ).click();
		await Crontrol.seeAdminSuccessNotice( 'Resumed the pause_me_soon hook.' );
		await expect( row.getByText( 'Edit' ) ).toBeVisible();
		await expect( row.getByText( 'Run now' ) ).toBeVisible();
		await expect( row.getByText( 'Pause this hook' ) ).toBeVisible();
		await expect( row.getByText( 'Resume' ) ).not.toBeVisible();
		await expect( row.getByText( 'Delete' ) ).toBeVisible();
	} );
} );
