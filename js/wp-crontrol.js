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
	$('#crontrol_tab_logs')
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
			pointerClass: 'wp-pointer arrow-top',
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
