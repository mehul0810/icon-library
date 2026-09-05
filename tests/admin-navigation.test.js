/* Node-only navigation race tests. No network or WordPress database required. */
const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const path = require( 'node:path' );

function harness( supportsAbort = true ) {
	const clicks = [];
	const events = {};
	const requests = [];
	const history = [];
	const classes = new Set();
	const storage = new Map();
	const admin = {
		innerHTML: 'initial',
		classList: { add: ( value ) => classes.add( value ), remove: ( value ) => classes.delete( value ) },
		querySelector: () => null,
		setAttribute() {}, removeAttribute() {},
	};
	const document = {
		querySelector: () => admin,
		addEventListener: ( type, callback ) => { if ( type === 'click' ) clicks.push( callback ); },
	};
	const window = {
		wp: {}, iconLibraryAdmin: null,
		location: { href: 'https://wp.test/wp-admin/themes.php?page=icon-library', origin: 'https://wp.test', assign: ( url ) => history.push( url ) },
		history: { pushState: ( state, title, url ) => history.push( url ) },
		sessionStorage: { getItem: ( key ) => storage.get( key ), setItem: ( key, value ) => storage.set( key, value ), removeItem: ( key ) => storage.delete( key ) },
		requestAnimationFrame: ( callback ) => callback(),
		addEventListener: ( type, callback ) => { events[ type ] = callback; },
		AbortController: supportsAbort ? AbortController : undefined,
		fetch: ( url, options ) => new Promise( ( resolve, reject ) => requests.push( { url, options, resolve, reject } ) ),
		DOMParser: class { parseFromString( html ) { return { title: html, querySelector: () => ( { innerHTML: html } ) }; } },
	};
	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../assets/admin.js' ), 'utf8' ), { window, document, URL } );
	function navigate( tab ) {
		const href = window.location.href + '&tab=' + tab;
		const link = { href, hasAttribute: () => false };
		clicks[0]( { button: 0, detail: 1, target: { closest: () => link }, preventDefault() {} } );
	}
	async function complete( index, html ) {
		requests[index].resolve( { ok: true, text: async () => html } );
		await new Promise( ( resolve ) => setImmediate( resolve ) );
	}
	return { admin, requests, history, events, window, navigate, complete };
}

for ( const supportsAbort of [ true, false ] ) {
	test( `latest navigation wins (AbortController: ${ supportsAbort })`, async () => {
		const h = harness( supportsAbort );
		h.navigate( 'browse' );
		h.navigate( 'custom' );
		assert.equal( h.requests.length, 2 );
		if ( supportsAbort ) assert.equal( h.requests[0].options.signal.aborted, true );
		await h.complete( 1, 'custom' );
		await h.complete( 0, 'browse' );
		assert.equal( h.admin.innerHTML, 'custom' );
		assert.equal( h.history.length, 1 );
		assert.match( h.history[0], /tab=custom/ );
	} );
}

test( 'back navigation supersedes an in-flight link without adding history', async () => {
	const h = harness();
	h.navigate( 'browse' );
	h.events.popstate();
	await h.complete( 1, 'library' );
	await h.complete( 0, 'browse' );
	assert.equal( h.admin.innerHTML, 'library' );
	assert.equal( h.history.length, 0 );
} );
