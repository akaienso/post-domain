/**
 * The 200 ms the operator types into.
 *
 * The combobox debounces, so between an edit and the search that edit will
 * eventually start there is a window in which nothing new has been asked for
 * and the previous term's answers are still outstanding. A guard established
 * only when the later search *begins* leaves that window open: an older answer
 * — a "Show more results" page most of all, since those are the slow ones —
 * lands inside it and paints rows for a term that is no longer on screen. The
 * operator then clicks one, and the domain is mapped to something they did not
 * type.
 *
 * Timers: real ones, no clock injection. The window under test is 200 ms of
 * wall clock, and every step of the interesting part — the edit, the stale
 * answer landing, the assertions — runs on microtasks in the same tick, which
 * is orders of magnitude inside it. Faking the clock would replace the very
 * thing being measured, and the existing suite already waits the debounce out
 * with `settle()`; this file stays on the same footing.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const { createScreen, settle, flush, deferred, ok } = require( './helpers/harness.js' );

const ALPHA = { id: 11, title: 'Alpha pavilion', type: 'Page' };
const BETA = { id: 22, title: 'Beta pavilion', type: 'Page' };
const GAMMA = { id: 33, title: 'Gamma pavilion', type: 'Page' };

const NEEDLE = { id: 999, title: 'Pavilion twenty-five', type: 'Page' };

function page1() {
	const rows = [];

	for ( let i = 0; i < 20; i++ ) {
		rows.push( { id: 100 + i, title: 'Pavilion ' + i, type: 'Page' } );
	}

	return rows;
}

test( 'an answer issued before an edit is refused when it lands inside the debounce window', async () => {
	const slow = deferred();
	const screen = createScreen( {
		handler: ( call ) => ( 'alpha' === call.term ? slow.promise : ok( [ BETA ] ) )
	} );

	screen.type( 'alpha' );
	await settle();

	assert.equal( screen.calls.length, 1, 'the first search was issued' );
	assert.equal( screen.options().length, 0, 'and is still unanswered' );

	// The operator types over the term. Everything from here to the assertions
	// is microtasks, so the 200 ms timer for the new search has not fired.
	screen.type( 'beta' );

	slow.send( ok( [ ALPHA ] ) );
	await flush();
	await flush();

	assert.equal( screen.calls.length, 1, 'the replacement search has not even been asked for yet' );
	assert.deepEqual( screen.labels(), [], 'nothing may be painted for a term already typed over' );
	assert.equal( screen.list.hidden, true );
	assert.equal( screen.select.value, '', 'and no id may be left sitting in the field that submits' );

	// The newest answer, by contrast, is the one that renders and can be chosen.
	await settle();
	await flush();

	assert.deepEqual( screen.labels(), [ 'Beta pavilion (Page)' ] );

	screen.click( 0 );

	assert.equal( screen.select.value, '22' );
	assert.equal( screen.submit().defaultPrevented, false, 'the newest choice still submits' );

	screen.close();
} );

test( 'an old “Show more results” page is refused when it lands inside the debounce window', async () => {
	const slow = deferred();
	const screen = createScreen( {
		handler: ( call ) => {
			if ( 'pavilion' !== call.term ) {
				return ok( [ BETA ] );
			}

			return 1 === call.page ? ok( page1(), { page: 1, more: true, total: 26 } ) : slow.promise;
		}
	} );

	screen.type( 'pavilion' );
	await settle();
	await flush();

	assert.equal( screen.options().length, 21, 'twenty matches plus the way to the rest' );

	screen.click( 20 );

	assert.equal( screen.calls[ 1 ].page, 2, 'the continuation was asked for' );

	// A page request is the slow one, and this is exactly when the operator
	// gives up waiting and types something else.
	screen.type( 'beta' );

	slow.send( ok( [ NEEDLE ], { page: 2, more: false, total: 26 } ) );
	await flush();
	await flush();

	const labels = screen.labels();

	assert.equal( labels.length, 21, 'the abandoned page must not be appended to anything' );
	assert.equal( labels[ 20 ], 'Show more results', 'and must not replace the continuation row' );
	assert.equal(
		labels.includes( 'Pavilion twenty-five (Page)' ),
		false,
		'a row from a page the operator has typed over must never appear'
	);
	assert.equal( screen.select.value, '' );

	// And the search the edit scheduled runs, and answers for the term on screen.
	await settle();
	await flush();

	assert.deepEqual( screen.labels(), [ 'Beta pavilion (Page)' ] );

	screen.click( 0 );

	assert.equal( screen.select.value, '22', 'only the newest answer is selectable' );

	screen.close();
} );

test( 'a stale row cannot be selected once the visible query has changed', async () => {
	const slow = deferred();
	const screen = createScreen( {
		handler: ( call ) => {
			if ( 'gamma' === call.term ) {
				return ok( [ GAMMA ] );
			}

			return 'alpha' === call.term ? slow.promise : ok( [ BETA ] );
		}
	} );

	screen.type( 'gamma' );
	await settle();
	await flush();

	// A list is open and a row is highlighted, the way it is mid-keyboard-use.
	screen.key( 'ArrowDown' );
	assert.equal( screen.input.getAttribute( 'aria-activedescendant' ), 'pd-target-33' );

	screen.type( 'alpha' );
	await settle();

	assert.equal( screen.calls.length, 2, 'the second search was issued and is unanswered' );

	screen.type( 'beta' );

	assert.equal(
		screen.input.hasAttribute( 'aria-activedescendant' ),
		false,
		'an edit drops the highlight, so Enter cannot choose a row for the old term'
	);
	assert.deepEqual(
		screen.options().map( ( li ) => li.getAttribute( 'aria-selected' ) ),
		[ 'false' ],
		'no option stays marked selected once the query moved on'
	);

	const enter = screen.key( 'Enter' );

	assert.equal( enter.defaultPrevented, false, 'there is nothing active to choose' );
	assert.equal( screen.select.value, '', 'and Enter chose nothing' );

	slow.send( ok( [ ALPHA ] ) );
	await flush();
	await flush();

	assert.equal(
		screen.labels().includes( 'Alpha pavilion (Page)' ),
		false,
		'the abandoned term never gets a clickable row'
	);
	assert.equal( screen.select.value, '' );

	await settle();
	await flush();

	assert.deepEqual( screen.labels(), [ 'Beta pavilion (Page)' ] );

	screen.click( 0 );

	assert.equal( screen.select.value, '22' );

	screen.close();
} );
