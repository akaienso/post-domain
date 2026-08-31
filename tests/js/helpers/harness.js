/**
 * A real screen for assets/admin.js to run against.
 *
 * The script is progressive enhancement with no build step and no module
 * system, so the suite loads the very file the plugin ships — parsed and
 * executed as a browser would — rather than a copy shaped for testing. What it
 * exports on `window.postDomainAdminInternals` is only a set of handles onto
 * the functions the DOMContentLoaded listener already calls.
 */

'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE_PATH = path.join( __dirname, '..', '..', '..', 'assets', 'admin.js' );

const MARKUP = `<!doctype html><html><body>
	<form id="pd-form" method="post">
		<label for="pd_post_id">Shows this content</label>
		<div class="pd-combobox" data-pd-combobox
			data-nonce="test-nonce"
			data-action="pd_search_targets"
			data-endpoint="/wp-admin/admin-ajax.php">
			<select name="pd_post_id" id="pd_post_id" required>
				<option value="">&mdash; Choose &mdash;</option>
			</select>
		</div>
		<button type="submit">Add domain</button>
	</form>
</body></html>`;

/** A successful admin-ajax envelope, in the shape TargetSearch::search returns. */
function ok( results, meta ) {
	const settings = meta || {};

	return {
		success: true,
		data: {
			results,
			page: 'number' === typeof settings.page ? settings.page : 1,
			more: !! settings.more,
			total: 'number' === typeof settings.total ? settings.total : results.length
		}
	};
}

function error( message ) {
	return { success: false, data: { message } };
}

function response( body ) {
	return { json: () => Promise.resolve( body ) };
}

/**
 * Boots the script over the markup Screen renders, with a fetch under test control.
 *
 * `handler` receives { url, query, page, term } and returns either a body (sent
 * back immediately) or a promise, so a test can decide when — and in what
 * order — each answer lands.
 */
function createScreen( options ) {
	const settings = options || {};
	const dom = new JSDOM( MARKUP, {
		url: 'https://example.test/wp-admin/admin.php',
		// The point is to run the shipped file, so the page has to be able to run scripts.
		runScripts: 'dangerously'
	} );
	const { window } = dom;
	const calls = [];

	window.postDomainAdmin = settings.strings || {};

	const handler = settings.handler || ( () => ok( [] ) );

	window.fetch = ( url, init ) => {
		const parsed = new window.URL( String( url ), window.location.href );
		const call = {
			url: String( url ),
			init,
			term: parsed.searchParams.get( 'q' ),
			page: Number( parsed.searchParams.get( 'pd_page' ) )
		};

		calls.push( call );

		return Promise.resolve( handler( call ) ).then( ( body ) => response( body ) );
	};

	// eslint-disable-next-line no-eval -- loading the shipped file the way a page does.
	window.eval( fs.readFileSync( SOURCE_PATH, 'utf8' ) );

	const document = window.document;
	const root = document.querySelector( '[data-pd-combobox]' );

	window.postDomainAdminInternals.initCombobox( root );

	const input = root.querySelector( 'input[role="combobox"]' );

	return {
		window,
		document,
		root,
		calls,
		input,
		select: document.getElementById( 'pd_post_id' ),
		form: document.getElementById( 'pd-form' ),
		list: document.getElementById( 'pd_post_id_list' ),
		status: root.querySelector( '.pd-combobox__status' ),
		options: () => Array.from( root.querySelectorAll( '[role="option"]' ) ),
		labels: () => Array.from( root.querySelectorAll( '[role="option"]' ) ).map( ( li ) => li.textContent ),

		/** The label the moved <label for> now points at. */
		labelFor: () => document.querySelector( 'label' ).getAttribute( 'for' ),

		type( value ) {
			input.value = value;
			input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		},

		key( name ) {
			const event = new window.KeyboardEvent( 'keydown', {
				key: name,
				bubbles: true,
				cancelable: true
			} );

			input.dispatchEvent( event );

			return event;
		},

		click( index ) {
			const target = root.querySelectorAll( '[role="option"]' )[ index ];

			target.dispatchEvent( new window.MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
			target.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		},

		submit() {
			const event = new window.Event( 'submit', { bubbles: true, cancelable: true } );

			this.form.dispatchEvent( event );

			return event;
		},

		close() {
			dom.window.close();
		}
	};
}

/**
 * A body the test decides when to send.
 *
 * Returned from a `handler`, it lets a test hold one answer open, do something
 * else, and then choose the exact moment that answer lands.
 */
function deferred() {
	let release;
	const promise = new Promise( ( resolve ) => {
		release = resolve;
	} );

	return {
		promise,
		send( body ) {
			release( body );
		}
	};
}

/** The debounce in the script is real, so the suite waits it out rather than faking it. */
function settle( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, undefined === ms ? 260 : ms ) );
}

/** One turn of the microtask queue, for a promise that has already resolved. */
function flush() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

module.exports = { createScreen, settle, flush, deferred, ok, error };
