/* LorePort → Settings → Import: runs the MailPoet import in small steps and shows progress. */
( function () {
	var form = document.getElementById( 'loreport-import-form' );
	var box = document.getElementById( 'loreport-import-progress' );
	if ( ! form || ! box || ! window.loreportImport ) {
		return;
	}
	var config = window.loreportImport;
	var list = box.querySelector( 'ol' );
	var actions = box.querySelector( '.loreport-import__actions' );
	var current = null;

	function post( data ) {
		data.append( 'nonce', config.nonce );
		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: 'HTTP ' + response.status } };
			} );
		} );
	}

	function say( text, replace ) {
		if ( ! text ) {
			return;
		}
		if ( replace && current ) {
			current.textContent = text;
			return;
		}
		current = document.createElement( 'li' );
		current.textContent = text;
		list.appendChild( current );
	}

	function fail( message ) {
		say( config.strings.failed + ' ' + message );
		current = null;
		actions.innerHTML = '';
		var retry = document.createElement( 'button' );
		retry.type = 'button';
		retry.className = 'button';
		retry.textContent = config.strings.retry;
		retry.addEventListener( 'click', function () {
			actions.innerHTML = '';
			run();
		} );
		actions.appendChild( retry );
	}

	function run() {
		var data = new FormData();
		data.append( 'action', 'loreport_import_step' );
		post( data ).then( function ( result ) {
			if ( ! result.success ) {
				fail( ( result.data && result.data.message ) || '?' );
				return;
			}
			var r = result.data;
			if ( r.finished ) {
				say( config.strings.done );
				current = null;
				return;
			}
			// Progress within a step replaces its line; a finished step leaves its line and starts a new one.
			say( r.message, true );
			if ( r.done ) {
				current = null;
			}
			setTimeout( run, ( r.wait || 0 ) * 1000 );
		} ).catch( function ( error ) {
			fail( String( error ) );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		var data = new FormData( form );
		data.append( 'action', 'loreport_import_begin' );
		form.querySelector( '[type=submit]' ).disabled = true;
		box.hidden = false;
		list.innerHTML = '';
		current = null;
		say( config.strings.starting );
		post( data ).then( function ( result ) {
			if ( ! result.success ) {
				form.querySelector( '[type=submit]' ).disabled = false;
				fail( ( result.data && result.data.message ) || '?' );
				return;
			}
			current = null;
			run();
		} );
	} );
} )();
