/* LorePort's own Subscribers, Lists and Newsletters pages in a frame.
   Each time the page loads, WordPress asks LorePort for a one-use sign-in link
   (admin-ajax.php → POST /embed-sessions) and opens it in the frame. The frame
   says which page it's on, so this page's address follows (and a reload
   returns to the same place). */
( function () {
	var box = document.querySelector( '[data-loreport-embed]' );
	if ( ! box ) {
		return;
	}
	var frame = box.querySelector( 'iframe' );
	var loading = box.querySelector( '.loreport-frame__loading' );
	var error = box.querySelector( '.loreport-frame__error' );
	var origin = box.getAttribute( 'data-origin' );

	function fit() {
		var top = frame.getBoundingClientRect().top + window.scrollY;
		frame.style.height = Math.max( 480, window.innerHeight - top - 24 ) + 'px';
	}

	function fail( message, openUrl ) {
		loading.hidden = true;
		error.hidden = false;
		error.querySelector( 'p' ).textContent = message;
		if ( openUrl ) {
			var link = document.createElement( 'a' );
			link.href = openUrl;
			link.target = '_blank';
			link.rel = 'noopener';
			link.className = 'button';
			link.style.marginLeft = '8px';
			link.textContent = 'Open in LorePort';
			error.querySelector( 'p' ).appendChild( link );
		}
	}

	var signedInAt = 0;

	// Asks WordPress for a fresh one-use sign-in link and opens it in the frame.
	function open( path ) {
		var body = new FormData();
		body.append( 'action', 'loreport_embed_link' );
		body.append( '_ajax_nonce', box.getAttribute( 'data-nonce' ) );
		body.append( 'path', path );
		signedInAt = Date.now();

		fetch( box.getAttribute( 'data-ajax' ), { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result.success ) {
					frame.hidden = true;
					fail( ( result.data && result.data.message ) || 'LorePort couldn\'t be opened.', result.data && result.data.open );
					return;
				}
				frame.hidden = false;
				fit();
				frame.src = result.data.url;
			} )
			.catch( function () {
				fail( 'LorePort couldn\'t be reached. Please try again in a moment.' );
			} );
	}

	frame.addEventListener( 'load', function () {
		loading.hidden = true;
	} );
	open( box.getAttribute( 'data-path' ) );

	window.addEventListener( 'resize', fit );

	// The frame tells us which LorePort page it's on.
	window.addEventListener( 'message', function ( event ) {
		var data = event.data;
		if ( event.origin !== origin || event.source !== frame.contentWindow || ! data || data.source !== 'loreport' ) {
			return;
		}
		// The frame's session ended (a long idle spell, or the key changed): sign in
		// again at the same page. At most once a minute, so a browser that blocks
		// the frame's cookie gets LorePort's "Open LorePort" page, not a loop.
		if ( data.type === 'expired' ) {
			if ( Date.now() - signedInAt > 60000 ) {
				var url = new URL( window.location.href );
				var path = typeof data.path === 'string' && /^\/(subscribers|lists|newsletters)(\/|$|\?)/.test( data.path ) ? data.path : ( url.searchParams.get( 'path' ) || box.getAttribute( 'data-path' ) );
				open( path );
			}
			return;
		}
		if ( data.type !== 'navigate' ) {
			return;
		}
		if ( typeof data.path !== 'string' || ! /^\/(subscribers|lists|newsletters)(\/|$|\?)/.test( data.path ) ) {
			return;
		}
		var url = new URL( window.location.href );
		url.searchParams.set( 'path', data.path );
		window.history.replaceState( null, '', url.toString() );
		if ( typeof data.title === 'string' && data.title ) {
			document.title = data.title.replace( / - LorePort$/, '' ) + ' ‹ LorePort';
		}
	} );
}() );
