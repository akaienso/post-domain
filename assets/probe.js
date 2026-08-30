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

	/*
	 * Reaching this line at all is the result the origin test wants: the page is
	 * served by the plugin on the mapped host, so the request arrived at this
	 * WordPress installation under the mapped Host header. A hosting placeholder
	 * or a redirect to the primary domain serves something else and runs nothing,
	 * which is why silence is reported as "not confirmed" rather than a failure
	 * anyone has to interpret.
	 */
	if ( parent && params.get( 'origin' ) ) {
		window.parent.postMessage(
			{
				source: 'post-domain-probe',
				kind: 'origin',
				host: window.location.hostname,
				secure: 'https:' === window.location.protocol,
				token: params.get( 'origin' )
			},
			parent
		);
	}

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
