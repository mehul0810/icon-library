( function ( apiFetch, config ) {
	'use strict';

	if ( ! apiFetch || ! config ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.icon-library-toggle' );

		if ( ! form ) {
			return;
		}

		event.preventDefault();

		var button = form.querySelector( 'button[type="submit"]' );
		var status = document.querySelector( '.icon-library-status' );
		var state = form.dataset.state;
		var collection = form.dataset.collection;

		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		status.textContent = config.i18n.updating;

		apiFetch( {
			path: config.restPath + encodeURIComponent( collection ) + '/' + state,
			method: 'POST',
		} ).then( function () {
			var url = new URL( window.location.href );
			url.searchParams.set( 'icon-library-updated', '1' );
			window.location.assign( url.toString() );
		} ).catch( function ( error ) {
			button.disabled = false;
			form.removeAttribute( 'aria-busy' );
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	} );
} )( window.wp && window.wp.apiFetch, window.iconLibraryAdmin );
