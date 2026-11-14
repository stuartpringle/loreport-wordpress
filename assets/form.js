/* Sends LorePort signup forms in the background and shows the answer in place.
   Without JavaScript the form still works: it posts and comes back to the page. */
( function () {
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'loreport-signup__form' ) || ! window.fetch || ! window.FormData ) {
			return;
		}
		event.preventDefault();
		if ( form.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		var wrapper = form.closest( '.loreport-signup' );
		var message = form.querySelector( '.loreport-signup__message' );
		var messageText = form.querySelector( '.loreport-signup__message-text' ) || message;
		form.setAttribute( 'aria-busy', 'true' );

		fetch( form.action, {
			method: 'POST',
			body: new FormData( form ),
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				var good = [ 'pending', 'subscribed', 'already', 'queued' ].indexOf( result.status ) !== -1;
				messageText.textContent = result.message || '';
				message.className = 'loreport-signup__message ' + ( good ? 'is-good' : 'is-bad' );
				if ( good && wrapper ) {
					wrapper.classList.add( 'is-done' );
				}
			} )
			.catch( function () {
				// Couldn't use the background route: send the form the ordinary way.
				form.submit();
			} )
			.finally( function () {
				form.removeAttribute( 'aria-busy' );
			} );
	} );
}() );
