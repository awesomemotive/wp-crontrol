import { test, expect } from './utils/test-setup.js';

test.describe( 'Deleting All Events with a Hook', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Deleting a hook', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to delete all events with a given hook name'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amWorkingWithANewCronEvent( 'example_hook', '[1]' );
		await Crontrol.amWorkingWithANewCronEvent( 'example_hook', '[2]' );
		const row = await Crontrol.amWorkingWithANewCronEvent( 'example_hook', '[3]' );

		// Set up dialog handler to accept the popup
		page.on( 'dialog', dialog => dialog.accept() );

		await row.getByText( 'Delete all events with this hook (3)' ).first().click();
		await Crontrol.seeAdminSuccessNotice( 'Deleted all example_hook cron events.' );

		await expect( page.locator( '.crontrol-events' ) ).not.toContainText( 'example_hook' );
	} );

	test( 'Deleting a persistent WordPress core hook', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to delete all events with a persistent WordPress core hook'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[1]' );
		await Crontrol.amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[2]' );
		const row = await Crontrol.amWorkingWithANewCronEvent( 'wp_scheduled_delete', '[3]' );

		// Set up dialog handler to accept the popup
		page.on( 'dialog', dialog => dialog.accept() );

		await row.getByText( 'Delete all events with this hook (4)' ).first().click();
		await Crontrol.seeAdminSuccessNotice( 'Deleted all wp_scheduled_delete cron events.' );
	} );
} );
