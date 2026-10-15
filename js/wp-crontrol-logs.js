/**
 * Functionality related to WP Crontrol Pro.
 */

document.addEventListener( 'DOMContentLoaded', () => {
	const clearLogsButton = document.querySelector( '[data-crontrol-clear-logs]' );

	if ( clearLogsButton ) {
		clearLogsButton.addEventListener( 'click', ( event ) => {
			const form = clearLogsButton.closest( 'form' );
			const hookField = form ? form.querySelector( 'input[name="crontrol_hook"]' ) : null;
			const message = hookField && hookField.value
				? window.wpCrontrolPro.confirmClearLogsForHook.replace( '%s', hookField.value )
				: window.wpCrontrolPro.confirmClearLogs;

			const result = confirm( message );
			if ( ! result ) {
				event.preventDefault();
			}
		} );
	}

	const selectAll = document.querySelector( '[data-crontrol-select-all]' );

	if ( selectAll ) {
		const fieldset = selectAll.closest( 'form' ).querySelector( 'fieldset' );
		const hookBoxes = fieldset
			? Array.from( fieldset.querySelectorAll( 'input[name="crontrol_logged_hooks[]"]' ) )
			: [];

		selectAll.addEventListener( 'change', () => {
			hookBoxes.forEach( ( box ) => {
				box.checked = selectAll.checked;
			} );
		} );

		hookBoxes.forEach( ( box ) => {
			box.addEventListener( 'change', () => {
				selectAll.checked = hookBoxes.every( ( other ) => other.checked );
			} );
		} );
	}

	const viewButtons = document.querySelectorAll( '[data-crontrol-view-dialog]' );

	viewButtons.forEach( ( button ) => {
		const dialog = document.getElementById( button.dataset.crontrolViewDialog );

		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return;
		}

		button.addEventListener( 'click', () => {
			dialog.showModal();
		} );

		// Close the dialog when the backdrop (the dialog element itself) is clicked.
		dialog.addEventListener( 'click', ( event ) => {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
	} );

	const hookFilter = document.getElementById( 'crontrol-hook-filter' );

	if ( hookFilter && clearLogsButton ) {
		// The clear logs button acts on the currently applied filter, so disable it while
		// the dropdown selection differs from what has actually been applied.
		const appliedHook = hookFilter.value;

		hookFilter.addEventListener( 'change', () => {
			clearLogsButton.disabled = ( hookFilter.value !== appliedHook );
		} );
	}
} );
