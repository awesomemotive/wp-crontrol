import { test, expect } from './utils/test-setup';

test.describe( 'Event Arguments Toggle', () => {
	test.beforeAll( async ( { globalUtils } ) => {
		// Install WordPress fresh for this test suite
		globalUtils.installWordPress();
	} );

	test.beforeEach( async ( { Crontrol } ) => {
		// Login as admin before each test
		await Crontrol.loginViaPage( 'admin', 'password' );
	} );

	test( 'Event arguments are collapsible', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I need to be able to view event arguments in a collapsible toggle'
		}
	}, async ( {
		page,
		Crontrol,
	} ) => {
		// Create an event with arguments
		const hookName = 'test_event_with_args';
		const args = '["arg1","arg2"]';
		const row = await Crontrol.amWorkingWithANewCronEvent( hookName, args );

		// Check that the event row contains the collapsible arguments toggle
		await expect( row ).toContainText( 'View arguments' );

		// Verify the details element exists
		const details = row.locator( 'details' ).first();
		await expect( details ).toBeVisible();

		// Verify the details is initially closed (no open attribute)
		await expect( details ).not.toHaveAttribute( 'open' );

		// Click the summary to expand
		await details.locator( 'summary' ).click();

		// After expanding, the details should have the open attribute
		await expect( details ).toHaveAttribute( 'open' );

		// Now the arguments should be visible
		await expect( details ).toContainText( 'arg1' );
		await expect( details ).toContainText( 'arg2' );

		// Click again to collapse
		await details.locator( 'summary' ).click();

		// After collapsing, the details should no longer have the open attribute
		await expect( details ).not.toHaveAttribute( 'open' );
	} );

	test( 'Events without arguments don\'t show toggle', {
		annotation: {
			type: 'user-story',
			description: 'As an administrator, I should only see the arguments toggle for events that have arguments'
		}
	}, async ( {
		Crontrol,
	} ) => {
		// Create two events - one with args and one without
		const hookWithArgs = 'event_with_args';
		const hookWithoutArgs = 'event_without_args';

		// First create an event with arguments
		const rowWithArgs = await Crontrol.amWorkingWithANewCronEvent( hookWithArgs, '["test"]' );

		// Then create an event without arguments
		const rowWithoutArgs = await Crontrol.amWorkingWithANewCronEvent( hookWithoutArgs );

		// The event with arguments should have the toggle
		await expect( rowWithArgs.first() ).toContainText( hookWithArgs );
		await expect( rowWithArgs.first() ).toContainText( 'View arguments' );

		// The event without arguments should NOT have the toggle
		await expect( rowWithoutArgs.first() ).toContainText( hookWithoutArgs );
		await expect( rowWithoutArgs.first() ).not.toContainText( 'View arguments' );
	} );
} );
