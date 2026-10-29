/**
 * Plugin screens: connection test and confirmations for destructive actions.
 *
 * @package SKFreelancers\SheetsSync
 */
( function () {
	'use strict';

	var config = window.SKFSS_ADMIN;
	if ( ! config ) {
		return;
	}
	var i18n = config.i18n || {};

	/**
	 * Renders a result into a message box using text nodes only.
	 *
	 * @param {HTMLElement} box    Container.
	 * @param {string}      text   Headline.
	 * @param {string}      type   info|success|error.
	 * @param {Array=}      checks [{label, ok, message}].
	 */
	function render( box, text, type, checks ) {
		box.textContent = '';
		box.className = 'skfss-message notice inline notice-' + type;

		var p = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = text;
		p.appendChild( strong );
		box.appendChild( p );

		if ( checks && checks.length ) {
			var list = document.createElement( 'ul' );
			list.className = 'skfss-checks';
			checks.forEach( function ( check ) {
				var li = document.createElement( 'li' );
				li.className = check.ok ? 'skfss-ok' : 'skfss-bad';
				li.textContent = ( check.ok ? '\u2713 ' : '\u2715 ' ) + check.label + ': ' + check.message;
				list.appendChild( li );
			} );
			box.appendChild( list );
		}
	}

	var testButton = document.querySelector( '.skfss-connection-test' );
	if ( testButton ) {
		testButton.addEventListener( 'click', function () {
			var box = document.querySelector( '.skfss-wrap .skfss-message' );
			var body = new FormData();
			body.append( 'action', 'skfss_test_connection' );
			body.append( 'nonce', config.nonce );

			testButton.disabled = true;
			render( box, i18n.testing, 'info' );

			fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( ( json && json.data && json.data.message ) || i18n.requestFailed );
					}
					var ok = !! json.data.success;
					render( box, ( ok ? '\u2713 ' : '\u2715 ' ) + ( ok ? i18n.testOk : i18n.testFailed ), ok ? 'success' : 'error', json.data.checks || [] );
				} )
				.catch( function ( error ) {
					render( box, '\u2715 ' + i18n.testFailed + ': ' + ( error && error.message ? error.message : i18n.requestFailed ), 'error' );
				} )
				.then( function () {
					testButton.disabled = false;
				} );
		} );
	}

	/**
	 * Adds a confirmation prompt to a form.
	 *
	 * @param {string} selector Form selector.
	 * @param {string} message  Confirmation text.
	 */
	function confirmSubmit( selector, message ) {
		var form = document.querySelector( selector );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}
	}

	confirmSubmit( '.skfss-clear-form', i18n.confirmClear );
	confirmSubmit( '.skfss-disconnect-form', i18n.confirmDisconnect );
}() );
