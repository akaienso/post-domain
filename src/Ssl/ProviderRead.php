<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Support\HttpResponse;

/**
 * The typed outcome of a single provider read of a certificate resource.
 *
 * The payload is only meaningful when the state is PRESENT. Every other state
 * carries an empty payload, so nothing downstream can mistake a failed read for
 * a described resource.
 */
final class ProviderRead {

	/**
	 * @param array<string, mixed> $payload     The resource description, populated only for PRESENT.
	 * @param int|null             $retry_after Seconds the provider asked us to wait, when it said so.
	 * @param string|null          $code        A stable machine code describing why the read is not PRESENT.
	 */
	private function __construct(
		public readonly ProviderReadState $state,
		public readonly array $payload = array(),
		public readonly ?int $retry_after = null,
		public readonly ?string $code = null
	) {}

	/** @param array<string, mixed> $payload */
	public static function present( array $payload ): self {
		return new self( ProviderReadState::PRESENT, $payload );
	}

	public static function absent_unbound(): self {
		return new self( ProviderReadState::ABSENT_UNBOUND, array(), null, 'no_resource' );
	}

	public static function missing_bound(): self {
		return new self( ProviderReadState::MISSING_BOUND, array(), null, 'resource_missing' );
	}

	public static function transient( ?int $retry_after = null, string $code = 'transient' ): self {
		return new self( ProviderReadState::TRANSIENT, array(), $retry_after, $code );
	}

	public static function malformed( string $code = 'response_malformed' ): self {
		return new self( ProviderReadState::MALFORMED, array(), null, $code );
	}

	/**
	 * Classify one HTTP exchange.
	 *
	 * `$payload` is the already-decoded `result`, or null when the body carried
	 * no usable result. `$bound` says whether an `ssl_ref` is persisted for the
	 * mapping, which is the only thing that separates a confirmed absence from
	 * an anomaly. The transient test matches the driver's own, so a read is
	 * classified exactly the way a status or a removal is.
	 *
	 * @param array<string, mixed>|null $payload
	 */
	public static function classify( HttpResponse $response, ?array $payload, bool $bound ): self {
		if ( null !== $response->error || 0 === $response->status || $response->status >= 500 ) {
			return self::transient( null, 'unreachable' );
		}

		if ( 429 === $response->status ) {
			return self::transient( (int) ( $response->headers['retry-after'] ?? 60 ), 'rate_limited' );
		}

		if ( 404 === $response->status ) {
			return $bound ? self::missing_bound() : self::absent_unbound();
		}

		if ( null === $payload ) {
			return self::malformed(
				$response->status >= 200 && $response->status < 300 ? 'response_malformed' : 'provider_error'
			);
		}

		if ( array() === $payload ) {
			// A hostname query that matched nothing. Against a persisted ref the
			// same emptiness is an anomaly, not an answer.
			return $bound ? self::missing_bound() : self::absent_unbound();
		}

		return self::present( $payload );
	}
}
