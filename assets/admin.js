/**
 * Progressive enhancement for the Domain mappings screen.
 *
 * Everything here upgrades markup that already works without it. The content
 * control is a real <select> that submits and validates on its own; the copy
 * buttons fall back to a selection the operator can copy by hand; the countdown
 * only mirrors a limit the server enforces anyway. If this file fails to load,
 * the screen is plainer and still usable.
 */
( function () {
	'use strict';

	var strings = window.postDomainAdmin || {};

	function text( key, fallback ) {
		return strings[ key ] || fallback;
	}

	/* ---------------------------------------------------------------- copy */

	function announce( root, message ) {
		var status = root.querySelector( '.pd-copy__status' );

		if ( ! status ) {
			return;
		}

		status.textContent = message;

		window.setTimeout( function () {
			status.textContent = '';
		}, 2000 );
	}

	function selectValue( node ) {
		// The fallback when there is no Clipboard API, and after a refusal: the
		// value is selected so one keystroke copies it.
		var range = document.createRange();

		range.selectNodeContents( node );

		var selection = window.getSelection();

		if ( selection ) {
			selection.removeAllRanges();
			selection.addRange( range );
		}
	}

	function initCopy( root ) {
		var button = root.querySelector( '[data-pd-copy-button]' );
		var value  = root.querySelector( '[data-pd-copy-value]' );

		if ( ! button || ! value ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var content = value.textContent || '';

			if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
				selectValue( value );
				announce( root, text( 'copyManual', 'Selected — press Ctrl/Cmd+C' ) );

				return;
			}

			navigator.clipboard.writeText( content ).then(
				function () {
					announce( root, text( 'copied', 'Copied' ) );
				},
				function () {
					selectValue( value );
					announce( root, text( 'copyManual', 'Selected — press Ctrl/Cmd+C' ) );
				}
			);
		} );
	}

	/* ------------------------------------------------------------ combobox */

	/**
	 * Upgrades the native select into a searchable, paged combobox.
	 *
	 * The select is kept in the DOM and remains the field that submits, so the
	 * server sees the same input either way and no id can be posted that the
	 * server did not just offer.
	 *
	 * Two failures shaped this. The select used to keep whatever was chosen
	 * first: the operator chose A, typed over the text looking for B, submitted,
	 * and the domain silently went to A. And a slow answer to an older keystroke
	 * could land after a newer one and repopulate the list with the wrong
	 * search. So a choice is invalidated by the very next edit, and a response
	 * is identified by the request that asked for it rather than by arriving.
	 */
	function initCombobox( root ) {
		var select = root.querySelector( 'select' );

		if ( ! select || ! window.fetch ) {
			return;
		}

		var input = document.createElement( 'input' );

		input.type = 'text';
		input.className = 'regular-text';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'placeholder', text( 'searchPlaceholder', 'Search your content' ) );
		input.id = 'pd_post_id_search';

		var list = document.createElement( 'ul' );

		list.className = 'pd-combobox__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		list.id = 'pd_post_id_list';

		input.setAttribute( 'aria-controls', list.id );

		var status = document.createElement( 'p' );

		status.className = 'pd-combobox__status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		// The label already points at the select; move it to the control the
		// operator now types into, and keep the select reachable but out of the
		// tab order so there are not two controls for one field.
		var label = document.querySelector( 'label[for="' + select.id + '"]' );

		if ( label ) {
			label.setAttribute( 'for', input.id );
		}

		// Requiredness moves with it. A clipped select cannot be focused or
		// announced, so leaving `required` there makes the browser refuse to
		// submit while pointing at something the operator cannot see.
		var mustChoose = select.required;

		select.required = false;
		input.required  = mustChoose;

		select.setAttribute( 'aria-hidden', 'true' );
		select.tabIndex = -1;
		select.classList.add( 'pd-combobox__native' );
		select.style.position = 'absolute';
		select.style.width = '1px';
		select.style.height = '1px';
		select.style.overflow = 'hidden';
		select.style.clip = 'rect(1px,1px,1px,1px)';

		root.insertBefore( input, select );
		root.appendChild( list );
		root.appendChild( status );

		var active     = -1;
		var items      = [];
		var chosen     = null;
		var term       = '';
		var nextPage   = 1;
		var requestId  = 0;
		var controller = null;
		var timer      = null;

		function format( template, first, second ) {
			return String( template )
				.replace( '%1$d', String( first ) )
				.replace( '%2$d', String( second ) );
		}

		function isResult( item ) {
			return 'more' !== item.kind;
		}

		function shownCount() {
			var count = 0;

			items.forEach( function ( item ) {
				if ( isResult( item ) ) {
					count += 1;
				}
			} );

			return count;
		}

		/** Nothing is chosen any more, and nothing may be submitted. */
		function invalidate() {
			chosen = null;
			select.value = '';

			if ( mustChoose ) {
				input.setCustomValidity( text( 'chooseTarget', 'Choose a piece of content from the search results.' ) );
			}
		}

		function markChosen( id, labelText ) {
			chosen = String( id );
			select.value = chosen;
			input.value = labelText;
			input.setCustomValidity( '' );
		}

		function close() {
			var options = list.querySelectorAll( '[role="option"]' );

			for ( var i = 0; i < options.length; i++ ) {
				options[ i ].setAttribute( 'aria-selected', 'false' );
				options[ i ].classList.remove( 'is-active' );
			}

			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function highlight( next ) {
			var options = list.querySelectorAll( '[role="option"]' );

			if ( ! options.length ) {
				return;
			}

			if ( active >= 0 && options[ active ] ) {
				options[ active ].setAttribute( 'aria-selected', 'false' );
				options[ active ].classList.remove( 'is-active' );
			}

			active = ( ( next % options.length ) + options.length ) % options.length;
			options[ active ].setAttribute( 'aria-selected', 'true' );
			options[ active ].classList.add( 'is-active' );
			input.setAttribute( 'aria-activedescendant', options[ active ].id );

			if ( options[ active ].scrollIntoView ) {
				options[ active ].scrollIntoView( { block: 'nearest' } );
			}
		}

		function choose( index ) {
			var item = items[ index ];

			if ( ! item ) {
				return;
			}

			if ( 'more' === item.kind ) {
				loadMore();

				return;
			}

			// Reflect the choice into the field that actually submits.
			var option = select.querySelector( 'option[value="' + item.id + '"]' );

			if ( ! option ) {
				option = document.createElement( 'option' );
				option.value = String( item.id );
				option.textContent = item.title;
				select.appendChild( option );
			}

			markChosen( item.id, item.title + ' (' + item.type + ')' );
			close();
		}

		function paint() {
			list.innerHTML = '';

			items.forEach( function ( item, index ) {
				var li = document.createElement( 'li' );

				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				li.tabIndex = -1;

				if ( 'more' === item.kind ) {
					li.id = 'pd-target-more';
					li.className = 'pd-combobox__more';
					li.setAttribute( 'data-pd-more', '' );
					li.textContent = text( 'loadMore', 'Show more results' );
				} else {
					li.id = 'pd-target-' + item.id;
					li.textContent = item.title + ' (' + item.type + ')';
				}

				// mousedown only holds the focus; the click is the choice, so a
				// pointer and a keyboard take exactly the same path.
				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
				} );

				li.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					choose( index );
				} );

				list.appendChild( li );
			} );
		}

		function render( results, meta, append ) {
			if ( append ) {
				items = items.filter( isResult );
			} else {
				items = [];
			}

			results.forEach( function ( result ) {
				items.push( {
					kind: 'result',
					id: result.id,
					title: result.title,
					type: result.type
				} );
			} );

			if ( ! items.length ) {
				status.textContent = text( 'noResults', 'Nothing matched that search.' );
				paint();
				close();

				// An empty search has nothing to submit, and must not leave the
				// previous answer's id sitting in the field.
				invalidate();

				return;
			}

			var more = !! ( meta && meta.more );

			if ( more ) {
				items.push( { kind: 'more' } );
			}

			paint();

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );

			var shown = shownCount();
			var total = ( meta && meta.total ) ? meta.total : shown;

			status.textContent = more
				? format(
					text( 'resultsPartial', 'Showing %1$d of %2$d matches. Choose “Show more results”, the last option in the list, for the next page.' ),
					shown,
					total
				)
				: format( text( 'resultsAll', 'Showing all %1$d matches.' ), shown, total );
		}

		function fail( message ) {
			status.textContent = message || text( 'searchError', 'That search could not be completed.' );
			items = [];
			paint();
			close();

			// A search that failed answered nothing, so nothing is chosen — and
			// no earlier id may be left behind to be posted in its place.
			invalidate();
		}

		function runSearch( page, append ) {
			var query = input.value.trim();

			if ( ! append ) {
				term = query;
			}

			// A monotonic id, so a slow answer to an older keystroke can never
			// overwrite the list a newer one is showing. The abort is the
			// courtesy; the id is the guarantee.
			requestId += 1;

			var id = requestId;

			if ( controller ) {
				controller.abort();
			}

			controller = window.AbortController ? new window.AbortController() : null;

			status.textContent = text( 'searching', 'Searching…' );

			var url = root.dataset.endpoint
				+ '?action=' + encodeURIComponent( root.dataset.action )
				+ '&nonce=' + encodeURIComponent( root.dataset.nonce )
				+ '&pd_page=' + encodeURIComponent( String( page ) )
				+ '&q=' + encodeURIComponent( term );

			var options = { credentials: 'same-origin' };

			if ( controller ) {
				options.signal = controller.signal;
			}

			var before = shownCount();

			fetch( url, options )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( body ) {
					if ( id !== requestId ) {
						return;
					}

					if ( ! body || ! body.success ) {
						fail( body && body.data && body.data.message );

						return;
					}

					var data = body.data || {};

					nextPage = ( data.page || page ) + 1;

					render( data.results || [], data, append );

					if ( append ) {
						// Land on the first newly loaded row, so continuing
						// through the list by keyboard never loses its place.
						highlight( before );
					}
				} )
				.catch( function () {
					if ( id !== requestId ) {
						return;
					}

					fail();
				} );
		}

		function loadMore() {
			runSearch( nextPage, true );
		}

		input.addEventListener( 'input', function () {
			// Every edit invalidates the previous choice immediately, before any
			// network round trip. Otherwise the operator chooses A, types over
			// the text looking for B, submits, and the domain silently goes to A.
			invalidate();
			nextPage = 1;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				runSearch( 1, false );
			}, 200 );
		} );

		input.addEventListener( 'focus', function () {
			if ( ! items.length ) {
				runSearch( 1, false );
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();

				if ( list.hidden && items.length ) {
					list.hidden = false;
					input.setAttribute( 'aria-expanded', 'true' );
				}

				highlight( active + 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();

				if ( list.hidden && items.length ) {
					list.hidden = false;
					input.setAttribute( 'aria-expanded', 'true' );
				}

				// From nothing, Up wraps onto the last option — which is where
				// the continuation row lives.
				highlight( active < 0 ? -1 : active - 1 );
			} else if ( 'Enter' === event.key && ! list.hidden && active >= 0 ) {
				event.preventDefault();
				choose( active );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 150 );
		} );

		var form = select.form || ( root.closest ? root.closest( 'form' ) : null );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( chosen || ! mustChoose ) {
					return;
				}

				var message = text( 'chooseTarget', 'Choose a piece of content from the search results.' );

				event.preventDefault();
				input.setCustomValidity( message );
				status.textContent = message;

				if ( input.reportValidity ) {
					input.reportValidity();
				}

				input.focus();
			} );
		}

		// An already-chosen value (an edit form) keeps its choice; anything else
		// starts with nothing selected and says so.
		if ( select.value ) {
			var current = select.options[ select.selectedIndex ];

			markChosen( select.value, current ? current.textContent : select.value );
		} else {
			invalidate();
		}
	}

	/* ----------------------------------------------------------- countdown */

	function initCountdown( button ) {
		var until = parseInt( button.dataset.pdCountdown, 10 );
		var slot  = button.querySelector( '.pd-countdown' );

		if ( ! until || ! slot ) {
			return;
		}

		function tick() {
			var left = until - Math.floor( Date.now() / 1000 );

			if ( left <= 0 ) {
				slot.textContent = '';
				button.disabled = false;
				window.clearInterval( handle );

				return;
			}

			var minutes = String( Math.floor( left / 60 ) ).padStart( 2, '0' );
			var seconds = String( left % 60 ).padStart( 2, '0' );

			slot.textContent = text( 'tryAgainIn', 'Try again in' ) + ' ' + minutes + ':' + seconds;
		}

		var handle = window.setInterval( tick, 1000 );

		tick();
	}

	/* --------------------------------------------------------- origin test */

	/**
	 * Loads the plugin's own probe page on the mapped host in a hidden frame.
	 *
	 * Only that page posts a message back. Anything the hosting serves instead —
	 * a placeholder, a redirect to the primary domain — runs no script, so the
	 * timeout is the answer rather than an error to explain away.
	 */
	function initOriginTest( root ) {
		var run = root.querySelector( '[data-pd-origin-run]' );
		var out = root.querySelector( '.pd-origin-test__result' );

		if ( ! run || ! out || ! window.fetch ) {
			return;
		}

		run.addEventListener( 'click', function () {
			var host     = root.dataset.host;
			var expected = 'https://' + host;
			var frame    = document.createElement( 'iframe' );

			run.disabled = true;
			out.textContent = text( 'testing', 'Testing…' );

			frame.hidden = true;
			frame.setAttribute( 'aria-hidden', 'true' );

			// The challenge is the server's, not this script's: it is issued once,
			// stored server-side, and spent on first use, so a proof cannot be
			// replayed and the page cannot choose what it will be asked to sign.
			frame.src = expected + '/.well-known/post-domain-probe?challenge='
				+ encodeURIComponent( root.dataset.challenge )
				+ '&parent=' + encodeURIComponent( window.location.origin );

			var settled = false;

			function finish( message ) {
				if ( settled ) {
					return;
				}

				settled = true;
				out.textContent = message;
				run.disabled = false;
				window.removeEventListener( 'message', onMessage );
				frame.remove();
			}

			function onMessage( event ) {
				/*
				 * Both checks matter and neither is sufficient alone. The origin
				 * says the message came from the mapped host rather than from any
				 * other frame or extension on the page; the source says it came
				 * from the frame this click opened rather than from some other
				 * window that happens to sit on that origin. Reading only
				 * `event.data`, as this first did, accepts a message from anyone.
				 */
				if ( event.origin !== expected ) {
					return;
				}

				if ( event.source !== frame.contentWindow ) {
					return;
				}

				var data = event.data;

				if ( ! data || 'post-domain-probe' !== data.source || 'origin' !== data.kind ) {
					return;
				}

				if ( ! data.payload || ! data.signature ) {
					return;
				}

				var body = new URLSearchParams();

				body.set( 'action', root.dataset.action );
				body.set( 'nonce', root.dataset.nonce );
				body.set( 'mapping', root.dataset.mapping );
				body.set( 'signature', data.signature );

				// The payload is forwarded verbatim. It is signed as a whole, so
				// the server rejects it if anything here altered a single field.
				Object.keys( data.payload ).forEach( function ( key ) {
					body.set( 'payload[' + key + ']', String( data.payload[ key ] ) );
				} );

				fetch( root.dataset.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						finish(
							( result && result.data && result.data.message )
								? result.data.message
								: text( 'testFailed', 'That test could not be completed.' )
						);

						if ( result && result.success ) {
							window.location.reload();
						}
					} )
					.catch( function () {
						finish( text( 'testFailed', 'That test could not be completed.' ) );
					} );
			}

			window.addEventListener( 'message', onMessage );
			document.body.appendChild( frame );

			// Silence is the expected shape of failure here — a placeholder or a
			// redirect serves a page that cannot sign anything — so it gets the
			// explanation rather than a bare timeout.
			window.setTimeout( function () {
				finish( text( 'testUnreachable', 'The domain did not reach this WordPress site. Your hosting may not be routing it here yet.' ) );
			}, 12000 );
		} );
	}

	/* ------------------------------------------------------------------ go */

	function init( scope ) {
		var doc = scope || document;

		doc.querySelectorAll( '[data-pd-copy]' ).forEach( initCopy );
		doc.querySelectorAll( '[data-pd-combobox]' ).forEach( initCombobox );
		doc.querySelectorAll( '[data-pd-countdown]' ).forEach( initCountdown );
		doc.querySelectorAll( '[data-pd-origin-test]' ).forEach( initOriginTest );
	}

	// Still self-starting: including the file on the page is all it takes.
	document.addEventListener( 'DOMContentLoaded', function () {
		init();
	} );

	// Named handles, so the behaviour above can be driven by a test rather than
	// only by a browser. Exporting them changes nothing about the line above.
	window.postDomainAdminInternals = {
		init: init,
		initCopy: initCopy,
		initCombobox: initCombobox,
		initCountdown: initCountdown,
		initOriginTest: initOriginTest
	};
}() );
