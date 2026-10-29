/**
 * Contact Form 7 editor: "Google Sheets" panel behaviour.
 *
 * Vanilla JavaScript, no dependencies. Every server call goes through
 * admin-ajax.php with the localized nonce; the server re-validates everything.
 * All text coming from Google (spreadsheet/worksheet names, headers) is inserted
 * with textContent / option.text, never as HTML.
 *
 * @package SKFreelancers\SheetsSync
 */
( function () {
	'use strict';

	var config = window.SKFSS_PANEL;
	var panel = document.getElementById( 'skfss-panel' );

	if ( ! config || ! panel ) {
		return;
	}

	var i18n = config.i18n || {};
	var els = {
		spreadsheet: panel.querySelector( '#skfss-spreadsheet' ),
		worksheet: panel.querySelector( '#skfss-worksheet' ),
		spreadsheetName: panel.querySelector( '.skfss-spreadsheet-name' ),
		worksheetName: panel.querySelector( '.skfss-worksheet-name' ),
		spreadsheetId: panel.querySelector( '.skfss-spreadsheet-id' ),
		worksheetId: panel.querySelector( '.skfss-worksheet-id' ),
		manualSpreadsheet: panel.querySelector( '#skfss-manual-spreadsheet' ),
		manualWorksheet: panel.querySelector( '#skfss-manual-worksheet' ),
		open: panel.querySelector( '.skfss-open' ),
		message: panel.querySelector( '.skfss-message' ),
		mapping: panel.querySelector( '.skfss-mapping tbody' )
	};
	var loaded = false;
	var busy = false;

	/**
	 * Formats a string containing a single %d / %s placeholder.
	 *
	 * @param {string} text  Text.
	 * @param {*}      value Value.
	 * @return {string} Formatted text.
	 */
	function format( text, value ) {
		return String( text || '' ).replace( /%[ds]/, String( value ) );
	}

	/**
	 * Shows a status message.
	 *
	 * @param {string}  text  Message.
	 * @param {string}  type  info|success|error|warning.
	 * @param {Array=}  items Optional check list [{label, ok, message}].
	 */
	function showMessage( text, type, items ) {
		var box = els.message;
		box.textContent = '';
		box.className = 'skfss-message notice inline notice-' + ( type || 'info' );

		var p = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = text;
		p.appendChild( strong );
		box.appendChild( p );

		if ( items && items.length ) {
			var list = document.createElement( 'ul' );
			list.className = 'skfss-checks';
			items.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.className = item.ok ? 'skfss-ok' : 'skfss-bad';
				li.textContent = ( item.ok ? '\u2713 ' : '\u2715 ' ) + item.label + ': ' + item.message;
				list.appendChild( li );
			} );
			box.appendChild( list );
		}
	}

	/**
	 * Posts an AJAX request.
	 *
	 * @param {string} action Action without prefix handling.
	 * @param {Object} data   Extra fields (arrays allowed).
	 * @return {Promise<Object>} Resolves with response data, rejects with Error.
	 */
	function request( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( i18n.requestFailed );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || i18n.requestFailed );
				}
				return json.data || {};
			} );
	}

	/**
	 * Current configuration mode.
	 *
	 * @return {string} select|manual.
	 */
	function configMode() {
		var checked = panel.querySelector( 'input[name="skfss[config_mode]"]:checked' );
		return checked ? checked.value : 'select';
	}

	/**
	 * Spreadsheet/worksheet currently chosen in the active mode.
	 *
	 * @return {{spreadsheet_id: string, worksheet_id: string, worksheet_name: string}} Target.
	 */
	function currentTarget() {
		if ( 'manual' === configMode() ) {
			return {
				spreadsheet_id: els.manualSpreadsheet.value.trim(),
				worksheet_id: '',
				worksheet_name: els.manualWorksheet.value.trim()
			};
		}
		return {
			spreadsheet_id: els.spreadsheet.value,
			worksheet_id: els.worksheet.value,
			worksheet_name: els.worksheetName.value
		};
	}

	/**
	 * Replaces a select's options.
	 *
	 * @param {HTMLSelectElement} select      Select.
	 * @param {string}            placeholder Placeholder text.
	 * @param {Array}             items       [{value, text, title}].
	 * @param {string}            selected    Value to keep selected.
	 * @param {string}            fallback    Text for a selected value missing from items.
	 */
	function fillSelect( select, placeholder, items, selected, fallback ) {
		select.textContent = '';
		select.appendChild( new Option( placeholder, '' ) );

		var found = false;
		items.forEach( function ( item ) {
			var option = new Option( item.text, item.value );
			if ( item.title !== undefined ) {
				option.setAttribute( 'data-title', item.title );
			}
			if ( item.value === selected ) {
				option.selected = true;
				found = true;
			}
			select.appendChild( option );
		} );

		if ( selected && ! found ) {
			var missing = new Option( fallback + ' ' + ( i18n.notInList || '' ), selected );
			missing.selected = true;
			select.appendChild( missing );
		}
	}

	/**
	 * Updates the "Open Spreadsheet" button.
	 *
	 * @param {string} url URL or ''.
	 */
	function setOpenUrl( url ) {
		if ( ! els.open ) {
			return;
		}
		if ( url ) {
			els.open.href = url;
			els.open.hidden = false;
		} else {
			els.open.removeAttribute( 'href' );
			els.open.hidden = true;
		}
	}

	/**
	 * Loads the spreadsheet list.
	 *
	 * @param {boolean} refresh Bypass the server cache.
	 */
	function loadSpreadsheets( refresh ) {
		if ( busy ) {
			return;
		}
		busy = true;
		showMessage( i18n.loadingSpreadsheets, 'info' );

		var selected = els.spreadsheet.value;
		var selectedText = selected ? els.spreadsheet.options[ els.spreadsheet.selectedIndex ].text : '';

		request( 'skfss_list_spreadsheets', { refresh: refresh ? '1' : '0' } )
			.then( function ( data ) {
				var files = data.spreadsheets || [];
				fillSelect(
					els.spreadsheet,
					i18n.chooseSpreadsheet,
					files.map( function ( file ) {
						return { value: file.id, text: file.name };
					} ),
					selected,
					selectedText
				);
				loaded = true;
				showMessage( files.length ? format( i18n.spreadsheetsLoaded, files.length ) : i18n.noSpreadsheets, files.length ? 'success' : 'warning' );
			} )
			.catch( function ( error ) {
				showMessage( i18n.listFailed + ' ' + error.message, 'error' );
			} )
			.then( function () {
				busy = false;
			} );
	}

	/**
	 * Loads worksheets for the selected spreadsheet.
	 *
	 * @param {boolean} refresh Bypass the server cache.
	 */
	function loadWorksheets( refresh ) {
		var id = els.spreadsheet.value;
		var keep = els.worksheet.value;

		els.spreadsheetId.textContent = id;
		els.spreadsheetName.value = id ? els.spreadsheet.options[ els.spreadsheet.selectedIndex ].text : '';

		if ( ! id ) {
			fillSelect( els.worksheet, i18n.chooseWorksheet, [], '', '' );
			syncWorksheet();
			setOpenUrl( '' );
			return;
		}

		showMessage( i18n.loadingWorksheets, 'info' );
		els.worksheet.disabled = true;

		request( 'skfss_list_worksheets', { spreadsheet_id: id, refresh: refresh ? '1' : '0' } )
			.then( function ( data ) {
				var sheets = data.worksheets || [];
				var ids = sheets.map( function ( sheet ) {
					return sheet.id;
				} );
				var selected = -1 !== ids.indexOf( keep ) ? keep : ( 1 === sheets.length ? sheets[ 0 ].id : '' );

				fillSelect(
					els.worksheet,
					i18n.chooseWorksheet,
					sheets.map( function ( sheet ) {
						return { value: sheet.id, text: sheet.title, title: sheet.title };
					} ),
					selected,
					''
				);
				if ( data.spreadsheet && data.spreadsheet.name ) {
					els.spreadsheetName.value = data.spreadsheet.name;
				}
				setOpenUrl( data.spreadsheet ? data.spreadsheet.url : '' );
				syncWorksheet();
				showMessage( i18n.worksheetLoaded, 'success' );
			} )
			.catch( function ( error ) {
				fillSelect( els.worksheet, i18n.chooseWorksheet, [], '', '' );
				syncWorksheet();
				showMessage( error.message, 'error' );
			} )
			.then( function () {
				els.worksheet.disabled = false;
			} );
	}

	/**
	 * Copies the selected worksheet's title/ID into the hidden field and labels.
	 */
	function syncWorksheet() {
		var option = els.worksheet.options[ els.worksheet.selectedIndex ];
		var title = option && option.value ? option.getAttribute( 'data-title' ) || '' : '';

		els.worksheetName.value = title;
		els.worksheetId.textContent = els.worksheet.value;

		if ( els.open && ! els.open.hidden && els.open.href ) {
			var base = els.open.href.split( '#' )[ 0 ];
			els.open.href = els.worksheet.value ? base + '#gid=' + encodeURIComponent( els.worksheet.value ) : base;
		}
	}

	/**
	 * Runs the connection test for the current selection.
	 */
	function testConnection() {
		var target = currentTarget();
		if ( ! target.spreadsheet_id || ( ! target.worksheet_id && ! target.worksheet_name ) ) {
			showMessage( i18n.selectFirst, 'warning' );
			return;
		}
		showMessage( i18n.testing, 'info' );

		request( 'skfss_test_connection', target )
			.then( function ( data ) {
				showMessage( data.success ? '\u2713 ' + i18n.testOk : '\u2715 ' + i18n.testFailed, data.success ? 'success' : 'error', data.checks || [] );
			} )
			.catch( function ( error ) {
				showMessage( '\u2715 ' + i18n.testFailed + ': ' + error.message, 'error' );
			} );
	}

	/**
	 * Reads the sheet's headers and fills matching column names.
	 */
	function matchHeaders() {
		var target = currentTarget();
		if ( ! target.spreadsheet_id || ( ! target.worksheet_id && ! target.worksheet_name ) ) {
			showMessage( i18n.selectFirst, 'warning' );
			return;
		}

		var rows = Array.prototype.slice.call( els.mapping.querySelectorAll( 'tr.skfss-map-field' ) );
		target.fields = rows.map( function ( row ) {
			return row.getAttribute( 'data-field' );
		} );
		showMessage( i18n.matching, 'info' );

		request( 'skfss_get_headers', target )
			.then( function ( data ) {
				if ( ! data.headers || ! data.headers.length ) {
					showMessage( i18n.noHeaders, 'info' );
					return;
				}
				var suggestions = data.suggestions || {};
				var count = 0;
				rows.forEach( function ( row ) {
					var header = suggestions[ row.getAttribute( 'data-field' ) ];
					if ( header ) {
						row.querySelector( 'input[data-key="column"]' ).value = header;
						count++;
					}
				} );

				var manual = panel.querySelector( 'input[name="skfss[mapping_mode]"][value="manual"]' );
				if ( manual && count > 0 ) {
					manual.checked = true;
					applyMappingMode();
				}
				showMessage( format( i18n.matched, count ), 'success' );
			} )
			.catch( function ( error ) {
				showMessage( error.message, 'error' );
			} );
	}

	/**
	 * Rewrites mapping input names so PHP receives rows in display order.
	 */
	function reindexMapping() {
		Array.prototype.forEach.call( els.mapping.querySelectorAll( 'tr.skfss-map-row' ), function ( row, index ) {
			Array.prototype.forEach.call( row.querySelectorAll( '[data-key]' ), function ( input ) {
				input.name = 'skfss[mapping][' + index + '][' + input.getAttribute( 'data-key' ) + ']';
			} );
		} );
	}

	/**
	 * Moves a mapping row up or down.
	 *
	 * @param {HTMLElement} button Move button.
	 */
	function moveRow( button ) {
		var row = button.closest( 'tr' );
		if ( 'up' === button.getAttribute( 'data-dir' ) && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
		} else if ( 'down' === button.getAttribute( 'data-dir' ) && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
		}
		reindexMapping();
		button.focus();
	}

	/**
	 * Shows the right configuration table for the selected mode.
	 */
	function applyConfigMode() {
		var manual = 'manual' === configMode();
		panel.querySelector( '.skfss-config-select' ).hidden = manual;
		panel.querySelector( '.skfss-config-manual' ).hidden = ! manual;
	}

	/**
	 * In automatic mode, field column names are chosen by matching headers, so
	 * those inputs are read-only (they still post, keeping the saved values).
	 */
	function applyMappingMode() {
		var checked = panel.querySelector( 'input[name="skfss[mapping_mode]"]:checked' );
		var auto = ! checked || 'auto' === checked.value;
		Array.prototype.forEach.call( els.mapping.querySelectorAll( 'tr.skfss-map-field input[data-key="column"]' ), function ( input ) {
			input.readOnly = auto;
		} );
		var help = panel.querySelector( '.skfss-auto-help' );
		if ( help ) {
			help.hidden = ! auto;
		}
	}

	/**
	 * Shows "Rows to keep at the top" only for newest-first.
	 */
	function applyRowOrder() {
		var checked = panel.querySelector( 'input[name="skfss[row_order]"]:checked' );
		var box = panel.querySelector( '.skfss-top-rows' );
		if ( box ) {
			box.hidden = ! checked || 'prepend' !== checked.value;
		}
	}

	panel.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( 'skfss[row_order]' === target.name ) {
			applyRowOrder();
		} else if ( 'skfss[config_mode]' === target.name ) {
			applyConfigMode();
		} else if ( 'skfss[mapping_mode]' === target.name ) {
			applyMappingMode();
		} else if ( target === els.spreadsheet ) {
			els.worksheet.value = '';
			loadWorksheets( false );
		} else if ( target === els.worksheet ) {
			syncWorksheet();
		}
	} );

	panel.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button' );
		if ( ! button || ! panel.contains( button ) ) {
			return;
		}
		if ( button.classList.contains( 'skfss-refresh' ) ) {
			event.preventDefault();
			loadSpreadsheets( true );
			if ( els.spreadsheet.value ) {
				loadWorksheets( true );
			}
		} else if ( button.classList.contains( 'skfss-test' ) ) {
			event.preventDefault();
			testConnection();
		} else if ( button.classList.contains( 'skfss-match' ) ) {
			event.preventDefault();
			matchHeaders();
		} else if ( button.classList.contains( 'skfss-move' ) ) {
			event.preventDefault();
			moveRow( button );
		}
	} );

	// Load the list lazily: only when the administrator opens the dropdown.
	[ 'focus', 'mousedown' ].forEach( function ( type ) {
		els.spreadsheet.addEventListener( type, function () {
			if ( ! loaded && ! busy ) {
				loadSpreadsheets( false );
			}
		} );
	} );

	applyConfigMode();
	applyMappingMode();
	applyRowOrder();
}() );
