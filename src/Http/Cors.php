<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;

/**
 * Authorizes the REQUESTING origin, on whichever host serves the asset. Never
 * '*', never an unvalidated echo.
 */
final class Cors {

	private const ORIGIN_GRAMMAR = '~^(https?)://([a-z0-9._\~%-]+|\[[0-9a-fA-F:.]+\])(:\d{1,5})?$~';

	public function __construct( private readonly MappingRepository $repo ) {}

	public function register(): void {
		add_action( 'send_headers', array( $this, 'send' ) );
	}

	public function send(): void {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ORIGIN'] ) )
			: '';

		$https = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'];

		$allowed = $this->allowed_origin( $origin, $https );

		if ( null === $allowed ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $allowed );
		header( 'Vary: Origin', false );
	}

	public function allowed_origin( string $origin_header, bool $request_is_https ): ?string {
		if ( '' === $origin_header || 'null' === $origin_header ) {
			return null;
		}

		if ( 1 !== preg_match( self::ORIGIN_GRAMMAR, $origin_header, $matches ) ) {
			return null;
		}

		if ( $request_is_https && 'https' !== $matches[1] ) {
			return null;
		}

		$authority = ( new AuthorityParser() )->parse( $matches[2] . ( $matches[3] ?? '' ) );

		if ( null === $authority ) {
			return null;
		}

		$ascii = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $ascii ) {
			return null;
		}

		$mapping = $this->repo->by_host( $ascii );

		if ( null === $mapping
			|| VerificationState::VERIFIED !== $mapping->verification_state
			|| ActivationState::ACTIVE !== $mapping->activation_state ) {
			return null;
		}

		/** @var string|null $filtered */
		$filtered = apply_filters( 'pd_cors_allowed_origin', $origin_header, $origin_header, $mapping );

		// Must be null or byte-identical to the validated request origin.
		return ( is_string( $filtered ) && $filtered === $origin_header ) ? $filtered : null;
	}
}
