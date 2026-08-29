/**
 * Runs on a MAPPED origin inside a hidden iframe, fetches a CORS-gated asset
 * from the primary host, and reports what it observed. The server never makes
 * this request: only the browser can produce the right Origin.
 */
( function () {
	'use strict';

	var params = new URLSearchParams( window.location.search );
	var asset  = params.get( 'asset' );
	var parent = params.get( 'parent' );

	if ( ! asset || ! parent ) {
		return;
	}

	fetch( asset, { mode: 'cors' } )
		.then(
			function () {
				window.parent.postMessage( { source: 'post-domain-probe', ok: true }, parent );
			}
		)
		.catch(
			function ( error ) {
				window.parent.postMessage(
					{ source: 'post-domain-probe', ok: false, reason: String( error ) },
					parent
				);
			}
		);

	window.addEventListener(
		'message',
		function ( event ) {
			if ( event.origin !== parent ) {
				return;
			}
		}
	);
}() );
