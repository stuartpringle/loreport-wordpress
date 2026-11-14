/* LorePort signup forms and popups.
   Forms are sent in the background and show the answer in place; without
   JavaScript they still work (they post and come back to the page).
   Popups are <dialog class="loreport-popup"> elements, opened by any link or
   button with data-loreport-open (optionally naming a popup's id) or a link
   to #loreport-popup, and optionally on their own after a delay. */
( function () {
	var REMEMBER_DAYS = 30;

	function remembered( id ) {
		try {
			var at = parseInt( window.localStorage.getItem( 'loreport-popup:' + id ) || '0', 10 );
			return at > 0 && Date.now() - at < REMEMBER_DAYS * 864e5;
		} catch ( e ) {
			return false;
		}
	}

	function remember( id ) {
		try {
			window.localStorage.setItem( 'loreport-popup:' + id, String( Date.now() ) );
		} catch ( e ) {}
	}

	// A popup sits in the top layer, away from the page's backgrounds: borrow
	// the nearest one, so light text on a dark site stays readable.
	function pageBackground( el ) {
		for ( var node = el.parentElement; node; node = node.parentElement ) {
			var bg = window.getComputedStyle( node ).backgroundColor;
			if ( bg && bg !== 'transparent' && ! /,\s*0\)$/.test( bg ) ) {
				return bg;
			}
		}
		return '';
	}

	function openPopup( dialog ) {
		if ( ! dialog || dialog.open || typeof dialog.showModal !== 'function' ) {
			return false;
		}
		var panel = dialog.querySelector( '.loreport-popup__panel' );
		if ( panel && ! window.getComputedStyle( dialog ).getPropertyValue( '--loreport-popup-bg' ).trim() ) {
			panel.style.background = pageBackground( dialog );
		}
		dialog.showModal();
		document.documentElement.classList.add( 'loreport-popup-open' );
		var email = dialog.querySelector( 'input[type="email"]' );
		if ( email && ! dialog.querySelector( '.is-done' ) ) {
			email.focus();
		}
		return true;
	}

	function findPopup( name ) {
		return ( name && document.getElementById( name ) ) || document.querySelector( 'dialog.loreport-popup' );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target.closest ) {
			return;
		}
		var trigger = target.closest( '[data-loreport-open], a[href$="#loreport-popup"]' );
		if ( trigger ) {
			if ( openPopup( findPopup( trigger.getAttribute( 'data-loreport-open' ) || '' ) ) ) {
				event.preventDefault();
			}
			return;
		}

		var dialog = target.closest( 'dialog.loreport-popup' );
		// A click on the dim area around the panel lands on the dialog itself.
		if ( dialog && ( target === dialog || target.closest( '[data-loreport-close]' ) ) ) {
			event.preventDefault();
			dialog.close();
		}
	} );

	document.addEventListener( 'close', function ( event ) {
		var dialog = event.target;
		if ( dialog.classList && dialog.classList.contains( 'loreport-popup' ) ) {
			document.documentElement.classList.remove( 'loreport-popup-open' );
			remember( dialog.id ); // Closed or signed up: don't pop up on its own again for a while.
		}
	}, true );

	function autoOpen() {
		var dialogs = document.querySelectorAll( 'dialog.loreport-popup[data-loreport-delay]' );
		Array.prototype.forEach.call( dialogs, function ( dialog ) {
			var delay = parseInt( dialog.getAttribute( 'data-loreport-delay' ), 10 );
			if ( delay > 0 && ! remembered( dialog.id ) ) {
				window.setTimeout( function () {
					if ( ! document.querySelector( 'dialog[open]' ) ) {
						openPopup( dialog );
					}
				}, delay * 1000 );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', autoOpen );
	} else {
		autoOpen();
	}

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
					var dialog = form.closest( 'dialog.loreport-popup' );
					if ( dialog ) {
						remember( dialog.id );
					}
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
