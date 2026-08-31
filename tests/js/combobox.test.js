/**
 * The content combobox, driven the way an operator drives it.
 *
 * These are behaviour tests, not syntax checks. Each one names a way the
 * control could quietly map a domain to the wrong post, or make a target
 * unreachable, and then does the thing that used to cause it.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const { createScreen, settle, flush, ok, error } = require( './helpers/harness.js' );

const ALPHA = { id: 11, title: 'Alpha pavilion', type: 'Page' };
const BETA = { id: 22, title: 'Beta pavilion', type: 'Page' };

/** Types a term, waits out the debounce, and lets the answer land. */
async function search( screen, term ) {
	screen.type( term );
	await settle();
	await flush();
}

test( 'editing the text after a choice does not submit the earlier choice', async () => {
	const screen = createScreen( {
		handler: ( call ) => ok( 'alpha'.startsWith( call.term.toLowerCase().slice( 0, 5 ) ) ? [ ALPHA ] : [ BETA ] )
	} );

	await search( screen, 'alpha' );
	screen.click( 0 );

	assert.equal( screen.select.value, '11', 'choosing must reflect into the field that submits' );

	// The operator now goes looking for something else and submits without
	// picking it. Nothing may be posted.
	screen.type( 'beta' );

	assert.equal( screen.select.value, '', 'the previous choice is invalidated by the very next edit' );

	const submitted = screen.submit();

	assert.equal( submitted.defaultPrevented, true, 'submission must be refused with nothing chosen' );
	assert.equal( screen.select.value, '', 'and A must not be what goes to the server' );
	assert.equal( screen.document.activeElement, screen.input, 'focus goes to the control that needs attention' );
	assert.notEqual( screen.input.validationMessage, '', 'and the refusal is announced, not silent' );
	assert.match( screen.status.textContent, /Choose a piece of content/ );

	screen.close();
} );

test( 'a choice that is left alone is the choice that submits', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA, BETA ] ) } );

	await search( screen, 'pavilion' );
	screen.click( 0 );

	const submitted = screen.submit();

	assert.equal( submitted.defaultPrevented, false, 'a real choice must not be blocked' );
	assert.equal( screen.select.value, '11' );
	assert.equal( screen.input.validationMessage, '' );
	assert.equal( screen.input.value, 'Alpha pavilion (Page)' );

	screen.close();
} );

test( 'a slow answer to an older keystroke never overwrites a newer one', async () => {
	let releaseFirst;
	const firstLanded = new Promise( ( resolve ) => {
		releaseFirst = resolve;
	} );

	const screen = createScreen( {
		handler: ( call ) => {
			if ( 'alpha' === call.term ) {
				return firstLanded.then( () => ok( [ ALPHA ] ) );
			}

			return ok( [ BETA ] );
		}
	} );

	await search( screen, 'alpha' );

	assert.equal( screen.calls.length, 1 );
	assert.equal( screen.options().length, 0, 'the first answer has not arrived yet' );

	await search( screen, 'beta' );

	assert.deepEqual( screen.labels(), [ 'Beta pavilion (Page)' ] );

	// Now the stale answer finally lands.
	releaseFirst();
	await flush();
	await flush();

	assert.deepEqual( screen.labels(), [ 'Beta pavilion (Page)' ], 'the older response must be discarded' );

	screen.close();
} );

test( 'the list is reachable and choosable by keyboard alone', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA, BETA ] ) } );

	await search( screen, 'pavilion' );

	screen.key( 'ArrowDown' );
	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-11' );

	screen.key( 'ArrowDown' );
	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-22' );

	screen.key( 'ArrowUp' );
	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-11' );

	const enter = screen.key( 'Enter' );

	assert.equal( enter.defaultPrevented, true, 'Enter chooses rather than submitting the form' );
	assert.equal( screen.select.value, '11' );
	assert.equal( screen.list.hidden, true );

	screen.close();
} );

test( 'Escape closes the list without choosing anything', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA, BETA ] ) } );

	await search( screen, 'pavilion' );

	screen.key( 'ArrowDown' );
	screen.key( 'Escape' );

	assert.equal( screen.list.hidden, true );
	assert.equal( screen.select.value, '', 'Escape is not a choice' );

	screen.close();
} );

test( 'closing the list clears aria-activedescendant and every active option', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA, BETA ] ) } );

	await search( screen, 'pavilion' );

	screen.key( 'ArrowDown' );

	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-11' );
	assert.equal( screen.options()[ 0 ].getAttribute( 'aria-selected' ), 'true' );

	screen.key( 'Escape' );

	assert.equal( screen.input.hasAttribute( 'aria-activedescendant' ), false, 'no pointer to a hidden option' );
	assert.equal( screen.input.getAttribute( 'aria-expanded' ), 'false' );
	assert.deepEqual(
		screen.options().map( ( li ) => li.getAttribute( 'aria-selected' ) ),
		[ 'false', 'false' ],
		'no option is left marked selected in a closed list'
	);

	screen.close();
} );

test( 'a pointer chooses the row it clicked', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA, BETA ] ) } );

	await search( screen, 'pavilion' );
	screen.click( 1 );

	assert.equal( screen.select.value, '22' );
	assert.equal( screen.input.value, 'Beta pavilion (Page)' );
	assert.equal( screen.list.hidden, true );

	screen.close();
} );

test( 'a failed search leaves no id behind to be submitted', async () => {
	let fails = false;

	const screen = createScreen( {
		handler: () => ( fails ? error( 'That search could not be completed.' ) : ok( [ ALPHA ] ) )
	} );

	await search( screen, 'alpha' );
	screen.click( 0 );
	assert.equal( screen.select.value, '11' );

	fails = true;
	await search( screen, 'gamma' );

	assert.equal( screen.select.value, '', 'a failure must not leave the previous answer posted' );
	assert.equal( screen.options().length, 0 );
	assert.equal( screen.submit().defaultPrevented, true );

	screen.close();
} );

test( 'an empty search leaves no id behind to be submitted', async () => {
	let empty = false;

	const screen = createScreen( {
		handler: () => ( empty ? ok( [] ) : ok( [ ALPHA ] ) )
	} );

	await search( screen, 'alpha' );
	screen.click( 0 );
	assert.equal( screen.select.value, '11' );

	empty = true;
	await search( screen, 'nothing matches this' );

	assert.equal( screen.select.value, '' );
	assert.equal( screen.options().length, 0 );
	assert.match( screen.status.textContent, /Nothing matched/ );
	assert.equal( screen.submit().defaultPrevented, true );

	screen.close();
} );

test( 'a match beyond the first page can be reached, and says so while it can not', async () => {
	const first = [];

	for ( let i = 0; i < 20; i++ ) {
		first.push( { id: 100 + i, title: 'Pavilion ' + i, type: 'Page' } );
	}

	const needle = { id: 999, title: 'Pavilion twenty-five', type: 'Page' };

	const screen = createScreen( {
		handler: ( call ) => (
			1 === call.page
				? ok( first, { page: 1, more: true, total: 26 } )
				: ok( [ needle ], { page: 2, more: false, total: 26 } )
		)
	} );

	await search( screen, 'pavilion' );

	assert.equal( screen.options().length, 21, 'twenty matches plus the way to the rest' );
	assert.match( screen.status.textContent, /Showing 20 of 26/, 'the cutoff must not be silent' );

	const more = screen.options()[ 20 ];

	assert.equal( more.getAttribute( 'role' ), 'option', 'the continuation is in the list, not beside it' );
	assert.equal( more.textContent, 'Show more results' );

	// Reachable by keyboard: ArrowUp from nothing wraps onto the last option.
	screen.key( 'ArrowUp' );
	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-more' );

	screen.key( 'Enter' );
	await flush();
	await flush();

	assert.equal( screen.calls[ 1 ].page, 2, 'the next page is asked for by page number' );
	assert.equal( screen.calls[ 1 ].term, 'pavilion', 'and for the same search' );

	const labels = screen.labels();

	assert.equal( labels.length, 21, 'the earlier matches stay; the continuation is replaced' );
	assert.equal( labels[ 20 ], 'Pavilion twenty-five (Page)' );
	assert.match( screen.status.textContent, /Showing all 21 matches/ );

	screen.click( 20 );

	assert.equal( screen.select.value, '999', 'a match past the first response is selectable' );
	assert.equal( screen.submit().defaultPrevented, false );

	screen.close();
} );

test( 'the search endpoint is asked for a page, and the label moves to the control', async () => {
	const screen = createScreen( { handler: () => ok( [ ALPHA ] ) } );

	await search( screen, 'alpha' );

	assert.equal( screen.calls[ 0 ].page, 1 );
	assert.equal( screen.calls[ 0 ].term, 'alpha' );
	assert.match( screen.calls[ 0 ].url, /nonce=test-nonce/ );
	assert.equal( screen.labelFor(), 'pd_post_id_search', 'the label points at what the operator types into' );
	assert.equal( screen.select.required, false, 'validation lives on the visible control' );
	assert.equal( screen.input.required, true );

	screen.close();
} );

test( 'nothing is chosen before the operator chooses, and submitting says so', () => {
	const screen = createScreen( { handler: () => ok( [] ) } );

	assert.equal( screen.select.value, '' );
	assert.equal( screen.submit().defaultPrevented, true );
	assert.notEqual( screen.input.validationMessage, '' );

	screen.close();
} );
