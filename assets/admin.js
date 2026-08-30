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
	 * Upgrades the native select into a searchable combobox.
	 *
	 * The select is kept in the DOM and remains the field that submits, so the
	 * server sees the same input either way and no id can be posted that the
	 * server did not just offer.
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

		var active = -1;
		var items  = [];

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			active = -1;
		}

		function choose( index ) {
			var item = items[ index ];

			if ( ! item ) {
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

			select.value = String( item.id );
			input.value = item.title + ' (' + item.type + ')';
			close();
		}

		function render( results ) {
			items = results;
			list.innerHTML = '';

			if ( ! results.length ) {
				status.textContent = text( 'noResults', 'Nothing matched that search.' );
				close();

				return;
			}

			status.textContent = '';

			results.forEach( function ( item, index ) {
				var li = document.createElement( 'li' );

				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'id', 'pd-target-' + item.id );
				li.setAttribute( 'aria-selected', 'false' );
				li.tabIndex = -1;
				li.textContent = item.title + ' (' + item.type + ')';

				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( index );
				} );

				list.appendChild( li );
			} );

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function highlight( next ) {
			var options = list.querySelectorAll( '[role="option"]' );

			if ( ! options.length ) {
				return;
			}

			if ( active >= 0 && options[ active ] ) {
				options[ active ].setAttribute( 'aria-selected', 'false' );
			}

			active = ( next + options.length ) % options.length;
			options[ active ].setAttribute( 'aria-selected', 'true' );
			input.setAttribute( 'aria-activedescendant', options[ active ].id );
			options[ active ].scrollIntoView( { block: 'nearest' } );
		}

		var timer = null;

		function search() {
			var term = input.value.trim();

			status.textContent = text( 'searching', 'Searching…' );

			var url = root.dataset.endpoint
				+ '?action=' + encodeURIComponent( root.dataset.action )
				+ '&nonce=' + encodeURIComponent( root.dataset.nonce )
				+ '&q=' + encodeURIComponent( term );

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( body ) {
					if ( ! body || ! body.success ) {
						status.textContent = ( body && body.data && body.data.message )
							? body.data.message
							: text( 'searchError', 'That search could not be completed.' );
						close();

						return;
					}

					render( body.data.results || [] );
				} )
				.catch( function () {
					status.textContent = text( 'searchError', 'That search could not be completed.' );
					close();
				} );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( search, 200 );
		} );

		input.addEventListener( 'focus', function () {
			if ( ! list.children.length ) {
				search();
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( active + 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active - 1 );
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
			var host  = root.dataset.host;
			var token = String( Date.now() ) + '-' + Math.random().toString( 36 ).slice( 2 );
			var frame = document.createElement( 'iframe' );

			run.disabled = true;
			out.textContent = text( 'testing', 'Testing…' );

			frame.hidden = true;
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.src = 'https://' + host + '/.well-known/post-domain-probe?origin='
				+ encodeURIComponent( token )
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
				var data = event.data;

				if ( ! data || 'post-domain-probe' !== data.source || 'origin' !== data.kind ) {
					return;
				}

				if ( data.token !== token ) {
					return;
				}

				var body = new URLSearchParams();

				body.set( 'action', root.dataset.action );
				body.set( 'nonce', root.dataset.nonce );
				body.set( 'mapping', root.dataset.mapping );
				body.set( 'host', data.host || '' );
				body.set( 'secure', data.secure ? '1' : '0' );

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

			// Silence is the expected shape of failure here, so it gets the
			// explanation rather than a bare timeout.
			window.setTimeout( function () {
				finish( text( 'testUnreachable', 'The domain did not reach this WordPress site. Your hosting may not be routing it here yet.' ) );
			}, 12000 );
		} );
	}

	/* ------------------------------------------------------------------ go */

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-pd-copy]' ).forEach( initCopy );
		document.querySelectorAll( '[data-pd-combobox]' ).forEach( initCombobox );
		document.querySelectorAll( '[data-pd-countdown]' ).forEach( initCountdown );
		document.querySelectorAll( '[data-pd-origin-test]' ).forEach( initOriginTest );
	} );
}() );
