import { test, expect } from './utils/test-setup';

test.describe( 'Adding Cron Schedules', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Navigating to the Add Cron Schedules screen', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to navigate to the Cron Schedules screen'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amOnCronScheduleListingPage();
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h2' ) ).toContainText( 'Add Cron Schedule' );
	} );

	test( 'Adding a new schedule', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to add a custom cron schedule'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amOnCronScheduleListingPage();

		// Fill in the form fields
		await page.getByLabel( 'Internal Name' ).fill( 'my_schedule_name' );
		await page.getByLabel( 'Interval (seconds)' ).fill( '123' );
		await page.getByLabel( 'Display Name' ).fill( 'My Schedule Name' );

		// Submit the form
		await page.getByRole( 'button', { name: 'Add Cron Schedule' } ).click();

		// Verify success
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Schedules' );
		await Crontrol.seeAdminSuccessNotice( 'Added the cron schedule my_schedule_name.' );

		// Verify the schedule appears in the table
		const scheduleRow = page.locator( 'table tr' ).filter( { hasText: 'my_schedule_name' } );
		await expect( scheduleRow ).toBeVisible();
		await expect( scheduleRow.locator( '.column-crontrol_name' ) ).toContainText( 'my_schedule_name' );
		await expect( scheduleRow.locator( '.column-crontrol_interval' ) ).toContainText( '123' );
		await expect( scheduleRow.locator( '.column-crontrol_display' ) ).toContainText( 'My Schedule Name' );
	} );

	test( 'Adding a new schedule with a numeric internal name', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to add a cron schedule with a numeric internal name'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amOnCronScheduleListingPage();

		// Fill in the form fields with a numeric internal name
		await page.getByLabel( 'Internal Name' ).fill( '123' );
		await page.getByLabel( 'Interval (seconds)' ).fill( '456' );
		await page.getByLabel( 'Display Name' ).fill( 'Numeric Schedule' );

		// Submit the form
		await page.getByRole( 'button', { name: 'Add Cron Schedule' } ).click();

		// Verify success - the numeric name should be prefixed with 'schedule-'
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Schedules' );
		await Crontrol.seeAdminSuccessNotice( 'Added the cron schedule schedule-123.' );

		// Verify the schedule appears in the table with the prefixed name
		const scheduleRow = page.locator( 'table tr' ).filter( { hasText: 'schedule-123' } );
		await expect( scheduleRow ).toBeVisible();
		await expect( scheduleRow.locator( '.column-crontrol_name' ) ).toContainText( 'schedule-123' );
		await expect( scheduleRow.locator( '.column-crontrol_interval' ) ).toContainText( '456' );
		await expect( scheduleRow.locator( '.column-crontrol_display' ) ).toContainText( 'Numeric Schedule' );
	} );

	test( 'Adding a schedule with a duplicate WordPress core schedule name', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I should see an error when trying to add a schedule with a name that already exists in WordPress core'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amOnCronScheduleListingPage();

		// Try to add a schedule with the name 'hourly' which already exists
		await page.getByLabel( 'Internal Name' ).fill( 'hourly' );
		await page.getByLabel( 'Interval (seconds)' ).fill( '7200' );
		await page.getByLabel( 'Display Name' ).fill( 'My Custom Hourly' );

		// Submit the form
		await page.getByRole( 'button', { name: 'Add Cron Schedule' } ).click();

		// Verify error message
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Schedules' );
		await Crontrol.seeAdminErrorNotice( 'A schedule with the name hourly already exists.' );
	} );

	test( 'Adding a schedule with a duplicate custom schedule name', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I should see an error when trying to add a schedule with a name that already exists as a custom schedule'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		await Crontrol.amOnCronScheduleListingPage();

		// First, add a custom schedule
		await page.getByLabel( 'Internal Name' ).fill( 'my_custom_schedule' );
		await page.getByLabel( 'Interval (seconds)' ).fill( '1800' );
		await page.getByLabel( 'Display Name' ).fill( 'My Custom Schedule' );
		await page.getByRole( 'button', { name: 'Add Cron Schedule' } ).click();

		// Verify it was added
		await Crontrol.seeAdminSuccessNotice( 'Added the cron schedule my_custom_schedule.' );

		// Now try to add another schedule with the same name
		await page.getByLabel( 'Internal Name' ).fill( 'my_custom_schedule' );
		await page.getByLabel( 'Interval (seconds)' ).fill( '3600' );
		await page.getByLabel( 'Display Name' ).fill( 'Another Custom Schedule' );
		await page.getByRole( 'button', { name: 'Add Cron Schedule' } ).click();

		// Verify error message
		await expect( page.locator( '#crontrol-header' ) ).toContainText( 'Cron Schedules' );
		await expect( page.locator( 'h1' ) ).toContainText( 'Cron Schedules' );
		await Crontrol.seeAdminErrorNotice( 'A schedule with the name my_custom_schedule already exists.' );
	} );
} );
