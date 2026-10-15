/**
 * Functionality related to WP Crontrol.
 */

document.addEventListener( 'DOMContentLoaded', () => {
	const checkCustom = () => {
		document.getElementById( 'crontrol_next_run_date_local_custom' ).checked = true;
	};

	const customDateElement = document.getElementById( 'crontrol_next_run_date_local_custom_date' );
	const customTimeElement = document.getElementById( 'crontrol_next_run_date_local_custom_time' );
	const newCronElement = document.querySelector( 'input[value="new_cron"]' );
	const newURLCronElement = document.querySelector( 'input[value="new_url_cron"]' );
	const newPHPCronElement = document.querySelector( 'input[value="new_php_cron"]' );
	const hookCodeElement = document.getElementById( 'crontrol_hookcode' );
	const hookNameElement = document.getElementById( 'crontrol_hookname' );
	const hookURLElement = document.getElementById( 'crontrol_url' );
	const editEventElement = document.querySelector( '.crontrol-edit-event' );

	customDateElement && customDateElement.addEventListener( 'change', checkCustom );
	customTimeElement && customTimeElement.addEventListener( 'change', checkCustom );

	newCronElement && newCronElement.addEventListener( 'click', () => {
		editEventElement.classList.remove( 'crontrol-edit-event-url' );
		editEventElement.classList.remove( 'crontrol-edit-event-php' );
		editEventElement.classList.add( 'crontrol-edit-event-standard' );
		hookNameElement.setAttribute( 'required', true );
		hookURLElement.removeAttribute( 'required' );
	} );

	newURLCronElement && newURLCronElement.addEventListener( 'click', () => {
		editEventElement.classList.remove( 'crontrol-edit-event-standard' );
		editEventElement.classList.remove( 'crontrol-edit-event-php' );
		editEventElement.classList.add( 'crontrol-edit-event-url' );
		hookURLElement.setAttribute( 'required', true );
		hookNameElement.removeAttribute( 'required' );
	} );

	if ( newPHPCronElement ) {
		newPHPCronElement.addEventListener( 'click', () => {
			editEventElement.classList.remove( 'crontrol-edit-event-standard' );
			editEventElement.classList.remove( 'crontrol-edit-event-url' );
			editEventElement.classList.add( 'crontrol-edit-event-php' );
			hookNameElement.removeAttribute( 'required' );
			hookURLElement.removeAttribute( 'required' );
			if ( ! hookCodeElement.classList.contains( 'crontrol-editor-initialized' ) ) {
				wp.codeEditor.initialize( 'crontrol_hookcode', window.wpCrontrol.codeEditor );
			}
			hookCodeElement.classList.add( 'crontrol-editor-initialized' );
		} );
	} else if ( hookCodeElement ) {
		wp.codeEditor.initialize( 'crontrol_hookcode', window.wpCrontrol.codeEditor );
	}

	const deleteEventLinks = document.querySelectorAll( '[data-crontrol-delete-event]' );

	deleteEventLinks.forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			const result = confirm( window.wpCrontrol.confirmDeleteEvent );
			if ( ! result ) {
				event.preventDefault();
			}
		} );
	} );

	const deleteHookLinks = document.querySelectorAll( '[data-crontrol-delete-hook]' );

	deleteHookLinks.forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			const result = confirm( window.wpCrontrol.confirmDeleteHook );
			if ( ! result ) {
				event.preventDefault();
			}
		} );
	} );
} );

window.wpCrontrol.pointers?.logs && window.jQuery && jQuery( document ).ready( function($) {
	$('#crontrol_tab_logs,tr[data-slug="wp-crontrol"] .crontrol-logs')
		.pointer( {
			content:
				'<h3>' + window.wpCrontrol.pointers.logs.title + '<\/h3>' +
				window.wpCrontrol.pointers.logs.lines.map( function( line ) {
					return '<p>' + line + '<\/p>';
				} ).join( '' ),
			position: {
				edge: 'top',
				align: 'left'
			},
			pointerWidth: 300,
			close: () => {
				$.post(
					ajaxurl,
					{
						pointer: 'wp-crontrol-logs',
						action: 'dismiss-wp-pointer',
					}
				);
			},
		} )
		.pointer('open');
} );

// Dismissing a suggestion hides it in place and saves the dismissal in the background, so a
// half-written cron event form isn't lost. Without JavaScript the link reloads the page instead.
document.addEventListener( 'click', ( event ) => {
	const link = event.target.closest( '.crontrol-promo-dismiss' );

	if ( ! link || ! window.fetch ) {
		return;
	}

	const promo = link.closest( '[data-crontrol-promo]' );

	if ( ! promo ) {
		return;
	}

	event.preventDefault();

	if ( link.getAttribute( 'aria-disabled' ) ) {
		return;
	}

	// The suggestion stays until the server confirms the dismissal is saved.
	const label = link.textContent;
	link.setAttribute( 'aria-disabled', 'true' );
	link.style.opacity = '0.5';

	const url = new URL( link.href );
	url.searchParams.set( 'crontrol_dismiss_background', '1' );

	const controller = window.AbortController ? new AbortController() : null;
	const timeout = controller ? setTimeout( () => controller.abort(), 15000 ) : null;

	window.fetch( url.toString(), { credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
		.then( ( response ) => ( response.ok ? response.json() : Promise.reject() ) )
		.then( ( data ) => {
			if ( ! data || ! data.success ) {
				return Promise.reject();
			}
			promo.remove();
		} )
		.catch( () => {
			// Not saved, for example because the page has been open too long. Say so, and allow another try.
			link.removeAttribute( 'aria-disabled' );
			link.style.opacity = '';
			link.textContent = ( window.wpCrontrol && window.wpCrontrol.dismissFailed ) || label;
		} )
		.finally( () => {
			clearTimeout( timeout );
		} );
} );
