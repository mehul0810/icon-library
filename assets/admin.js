( function ( apiFetch, config ) {
	'use strict';
	var pendingFocusKey = 'iconLibraryPendingFocus';

	window.requestAnimationFrame( function () {
		var admin = document.querySelector( '.icon-library-admin' );

		if ( admin ) {
			admin.classList.add( 'has-js' );
			admin.classList.remove( 'is-loading' );
			focusPendingTarget( admin );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var link;
		var url;

		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		link = event.target.closest( '.icon-library-tab, .icon-library-collection-row, .icon-library-back, .icon-library-empty-state a' );

		if ( ! link || link.target || link.hasAttribute( 'download' ) ) {
			return;
		}

		url = new URL( link.href, window.location.href );

		if ( url.origin !== window.location.origin || 'icon-library' !== url.searchParams.get( 'page' ) ) {
			return;
		}

		event.preventDefault();
		navigateTo( url.toString(), true, 0 === event.detail );
	} );

	window.addEventListener( 'popstate', function () {
		navigateTo( window.location.href, false, false );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.icon-library-load-more-button' );
		var wrapper;
		var grid;
		var status;
		var strings;

		if ( ! button || button.disabled ) {
			return;
		}

		wrapper = button.closest( '.icon-library-load-more' );
		grid    = document.getElementById( button.dataset.grid );
		status  = wrapper ? wrapper.querySelector( '.icon-library-load-more-status' ) : null;
		strings = config && config.i18n ? config.i18n : {};

		if ( ! wrapper || ! grid || ! button.dataset.url ) {
			return;
		}

		if ( ! window.fetch || ! window.DOMParser ) {
			window.location.assign( button.dataset.url );
			return;
		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		wrapper.classList.add( 'is-loading' );
		if ( status ) {
			status.textContent = strings.loadingMore || 'Loading more icons...';
		}

		window.fetch( button.dataset.url, {
			credentials: 'same-origin',
			headers: { Accept: 'text/html' },
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Load more request failed.' );
			}

			return response.text();
		} ).then( function ( html ) {
			var page = new window.DOMParser().parseFromString( html, 'text/html' );
			var nextGrid = page.getElementById( button.dataset.grid );
			var nextButton;
			var added;
			var loaded;
			var total;
			var template;

			if ( ! nextGrid ) {
				throw new Error( 'Load more response was incomplete.' );
			}

			added = nextGrid.children.length;
			while ( nextGrid.firstElementChild ) {
				grid.appendChild( nextGrid.firstElementChild );
			}

			loaded = grid.children.length;
			total  = parseInt( wrapper.dataset.total, 10 ) || loaded;
			wrapper.dataset.loaded = loaded;
			nextButton = page.querySelector( '.icon-library-load-more-button' );

			if ( nextButton && added ) {
				button.dataset.url         = nextButton.dataset.url;
				button.dataset.page        = nextButton.dataset.page;
				button.dataset.totalPages  = nextButton.dataset.totalPages;
				button.disabled             = false;
				button.removeAttribute( 'aria-busy' );
			} else {
				button.remove();
			}

			if ( status ) {
				template = strings.loadMoreStatus || 'Loaded %1$s of %2$s icons.';
				status.textContent = template.replace( '%1$s', loaded.toLocaleString() ).replace( '%2$s', total.toLocaleString() );
			}
			wrapper.classList.remove( 'is-loading' );
		} ).catch( function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			wrapper.classList.remove( 'is-loading' );
			if ( status ) {
				status.textContent = strings.loadMoreError || strings.error || 'More icons could not be loaded. Try again.';
			}
		} );
	} );

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
		var variant = form.dataset.variant;

		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		status.textContent = config.i18n.updating;

		apiFetch( {
			path: config.restPath + encodeURIComponent( collection ) + ( variant ? '/variants/' + encodeURIComponent( variant ) : '' ) + '/' + state,
			method: 'POST',
		} ).then( function () {
			var url = new URL( window.location.href );
			url.searchParams.set( 'icon-library-updated', '1' );
			navigateTo( url.toString(), true, '.icon-library-panel h2' );
		} ).catch( function ( error ) {
			button.disabled = false;
			form.removeAttribute( 'aria-busy' );
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var saveButton = event.target.closest( '.icon-library-custom-save' );
		var deleteButton = event.target.closest( '.icon-library-custom-delete' );
		var restoreButton = event.target.closest( '.icon-library-custom-restore' );
		var purgeButton = event.target.closest( '.icon-library-custom-purge' );
		var card = event.target.closest( '.icon-library-custom-icon' );

		if ( ! card || ( ! saveButton && ! deleteButton && ! restoreButton && ! purgeButton ) ) {
			return;
		}

		if ( deleteButton && ! window.confirm( config.i18n.deleteConfirm ) ) {
			return;
		}
		if ( purgeButton && ! window.confirm( config.i18n.purgeConfirm ) ) {
			return;
		}

		var status = document.querySelector( '.icon-library-status' );
		var name = card.dataset.name;
		var operation = restoreButton ? '/restore' : ( purgeButton ? '/purge' : '' );
		var request = {
			path: config.customPath + '/' + encodeURIComponent( name ),
			method: operation ? 'POST' : ( deleteButton ? 'DELETE' : 'PATCH' ),
		};
		if ( operation ) {
			request.path += operation;
		}

		if ( saveButton ) {
			request.data = { label: card.querySelector( '.icon-library-custom-label' ).value };
		}

		card.setAttribute( 'aria-busy', 'true' );
		status.textContent = config.i18n.updating;
		apiFetch( request ).then( function () {
			reloadWithSuccess( ( deleteButton || restoreButton || purgeButton ) ? '.icon-library-custom-heading' : customIconSelector( name ) + ' .icon-library-custom-save' );
		} ).catch( function ( error ) {
			card.removeAttribute( 'aria-busy' );
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	} );

	document.addEventListener( 'change', function ( event ) {
		var input = event.target.closest( '.icon-library-upload-area input[type="file"]' );

		if ( ! input || ! input.files.length ) {
			return;
		}

		var form = input.closest( 'form' );
		var fileName = input.files[ 0 ].name.replace( /\.svg$/i, '' );
		var slug = fileName.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /(^-|-$)/g, '' );
		var label = fileName.replace( /[-_]+/g, ' ' ).replace( /\b\w/g, function ( character ) {
			return character.toUpperCase();
		} );

		form.querySelector( 'input[name="name"]' ).value = slug;
		form.querySelector( 'input[name="label"]' ).value = label;
		form.querySelector( '.icon-library-upload-area span' ).textContent = input.files[ 0 ].name;
	} );

	function uploadCustomIcon( form ) {
		var fileInput = form.querySelector( 'input[name="svg"]' );
		var file = fileInput.files[ 0 ];
		var button = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		var status = document.querySelector( '.icon-library-status' );

		if ( ! file ) {
			return;
		}
		if ( 65536 < file.size ) {
			status.textContent = config.i18n.fileTooLarge;
			fileInput.focus();
			return;
		}

		form.setAttribute( 'aria-busy', 'true' );
		button.disabled = true;
		status.textContent = config.i18n.uploading;

		readFile( file ).then( function ( svg ) {
			return apiFetch( {
				path: config.customPath,
				method: 'POST',
				data: {
					name: form.querySelector( 'input[name="name"]' ).value,
					label: form.querySelector( 'input[name="label"]' ).value,
					svg: svg,
				},
			} );
		} ).then( function () {
			reloadWithSuccess( '.icon-library-custom-heading' );
		} ).catch( function ( error ) {
			form.removeAttribute( 'aria-busy' );
			button.disabled = false;
			status.textContent = error && error.message ? error.message : config.i18n.error;
		} );
	}

	function readFile( file ) {
		if ( file && 'function' === typeof file.text ) {
			return file.text();
		}

		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () { resolve( reader.result ); };
			reader.onerror = reject;
			reader.readAsText( file );
		} );
	}

	function reloadWithSuccess( focusSelector ) {
		var url = new URL( window.location.href );
		url.searchParams.set( 'icon-library-updated', '1' );
		navigateTo( url.toString(), true, focusSelector || '.icon-library-panel h2' );
	}

	function customIconSelector( name ) {
		var escaped = window.CSS && window.CSS.escape ? window.CSS.escape( name ) : name.replace( /[^a-z0-9-]/g, '' );
		return '.icon-library-custom-icon[data-name="' + escaped + '"]';
	}

	function navigateTo( url, addToHistory, moveFocus ) {
		var admin = document.querySelector( '.icon-library-admin' );
		var focusSelector = moveFocus ? ( 'string' === typeof moveFocus ? moveFocus : '.icon-library-panel h2, .icon-library-empty-state p' ) : '';

		if ( focusSelector ) {
			storePendingFocus( focusSelector );
		}

		if ( ! admin || admin.classList.contains( 'is-navigating' ) ) {
			return;
		}

		admin.classList.add( 'is-navigating' );
		admin.setAttribute( 'aria-busy', 'true' );

		window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Navigation request failed.' );
			}

			return response.text();
		} ).then( function ( html ) {
			var page = new window.DOMParser().parseFromString( html, 'text/html' );
			var nextAdmin = page.querySelector( '.icon-library-admin' );

			if ( ! nextAdmin ) {
				throw new Error( 'Navigation response was incomplete.' );
			}

			admin.innerHTML = nextAdmin.innerHTML;
			document.title = page.title;
			var nextStatus = admin.querySelector( '.icon-library-status' );
			if ( nextStatus && '1' === new URL( url, window.location.href ).searchParams.get( 'icon-library-updated' ) ) {
				nextStatus.textContent = config.i18n.updated;
			}

			if ( addToHistory ) {
				window.history.pushState( {}, '', url );
			}

			window.requestAnimationFrame( function () {
				admin.classList.remove( 'is-navigating' );
				admin.removeAttribute( 'aria-busy' );

				focusPendingTarget( admin );
			} );
		} ).catch( function () {
			window.location.assign( url );
		} );
	}

	function storePendingFocus( selector ) {
		try {
			window.sessionStorage.setItem( pendingFocusKey, selector );
		} catch ( error ) {
			// Focus restoration remains best-effort when storage is unavailable.
		}
	}

	function focusPendingTarget( admin ) {
		var selector;
		var focusTarget;

		try {
			selector = window.sessionStorage.getItem( pendingFocusKey );
			window.sessionStorage.removeItem( pendingFocusKey );
		} catch ( error ) {
			return;
		}

		if ( ! selector ) {
			return;
		}

		focusTarget = admin.querySelector( selector );
		if ( focusTarget ) {
			focusTarget.setAttribute( 'tabindex', '-1' );
			focusTarget.focus( { preventScroll: true } );
		}
	}
} )( window.wp && window.wp.apiFetch, window.iconLibraryAdmin );
