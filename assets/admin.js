( function ( apiFetch, config ) {
	'use strict';

	if ( ! apiFetch || ! config ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.icon-library-toggle' );
		var uploadForm = event.target.closest( '.icon-library-custom-upload' );

		if ( uploadForm ) {
			event.preventDefault();
			uploadCustomIcon( uploadForm );
			return;
		}

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

	document.addEventListener( 'click', function ( event ) {
		var saveButton = event.target.closest( '.icon-library-custom-save' );
		var deleteButton = event.target.closest( '.icon-library-custom-delete' );
		var card = event.target.closest( '.icon-library-custom-icon' );

		if ( ! card || ( ! saveButton && ! deleteButton ) ) {
			return;
		}

		if ( deleteButton && ! window.confirm( config.i18n.deleteConfirm ) ) {
			return;
		}

		var status = document.querySelector( '.icon-library-status' );
		var name = card.dataset.name;
		var request = {
			path: config.customPath + '/' + encodeURIComponent( name ),
			method: deleteButton ? 'DELETE' : 'PATCH',
		};

		if ( saveButton ) {
			request.data = { label: card.querySelector( '.icon-library-custom-label' ).value };
		}

		card.setAttribute( 'aria-busy', 'true' );
		status.textContent = config.i18n.updating;
		apiFetch( request ).then( reloadWithSuccess ).catch( function ( error ) {
			card.removeAttribute( 'aria-busy' );
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	} );

	function uploadCustomIcon( form ) {
		var fileInput = form.querySelector( 'input[name="svg"]' );
		var file = fileInput.files[ 0 ];
		var button = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		var status = document.querySelector( '.icon-library-status' );

		if ( ! file ) {
			return;
		}

		form.setAttribute( 'aria-busy', 'true' );
		button.disabled = true;
		status.textContent = config.i18n.uploading;

		file.text().then( function ( svg ) {
			return apiFetch( {
				path: config.customPath,
				method: 'POST',
				data: {
					name: form.querySelector( 'input[name="name"]' ).value,
					label: form.querySelector( 'input[name="label"]' ).value,
					svg: svg,
				},
			} );
		} ).then( reloadWithSuccess ).catch( function ( error ) {
			form.removeAttribute( 'aria-busy' );
			button.disabled = false;
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	}

	function reloadWithSuccess() {
		var url = new URL( window.location.href );
		url.searchParams.set( 'icon-library-updated', '1' );
		window.location.assign( url.toString() );
	}
} )( window.wp && window.wp.apiFetch, window.iconLibraryAdmin );
