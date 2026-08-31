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
	 * The signed statement this installation embedded when it served this page.
	 *
	 * Its presence is the evidence: a hosting placeholder or a redirect to the
	 * primary domain serves something else, cannot produce the signature, and
	 * reports nothing at all — which the parent reads as "not confirmed" rather
	 * than as a failure anyone has to interpret. Nothing is asserted here about
	 * the hostname or the scheme, because a claim from this side is worth
	 * nothing; the server checks the signed payload instead.
	 */
	var proofNode = document.getElementById( 'pd-origin-proof' );

	if ( parent && proofNode ) {
		try {
			var proof = JSON.parse( proofNode.textContent || '{}' );

			window.parent.postMessage(
				{
					source: 'post-domain-probe',
					kind: 'origin',
					payload: proof.payload,
					signature: proof.signature
				},
				parent
			);
		} catch ( error ) {
			// A malformed proof is silence, which is the honest answer.
		}
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
