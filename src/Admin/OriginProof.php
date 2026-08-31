<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\Mapping;

/**
 * Proof that this installation answered a request on the mapped host.
 *
 * The first design passed a random token to the probe page and believed
 * whatever came back that echoed it. Anything served at the mapped hostname can
 * read that token out of its own URL and reply, so it proved only that *some*
 * page loaded — precisely the thing under test. A hosting placeholder that
 * happened to run script could have claimed success.
 *
 * So the probe endpoint signs instead. It runs inside this installation, holds
 * the site's salts, and signs a statement about what it just resolved. Nothing
 * else can produce that signature, and the claim is bound to the exact mapping
 * state it was made about, so it cannot be replayed against a row that has since
 * moved.
 */
final class OriginProof {

	/** Long enough for a page load, short enough to be worthless later. */
	public const TTL = 300;

	private const CONTEXT = 'post-domain-origin-proof';

	/**
	 * A statement about what this installation served, and its signature.
	 *
	 * @return array{payload: array<string, string|int>, signature: string}
	 */
	public static function issue( Mapping $mapping, string $challenge, string $host ): array {
		$payload = self::payload( $mapping, $challenge, $host, time() + self::TTL );

		return array(
			'payload'   => $payload,
			'signature' => self::sign( $payload ),
		);
	}

	/**
	 * Whether a returned proof really came from this installation, for this
	 * mapping, in its current state.
	 *
	 * @param array<string, mixed> $payload
	 * @return string|null The reason it was rejected, or null when it holds.
	 */
	public static function verify(
		array $payload,
		string $signature,
		Mapping $mapping,
		string $expected_challenge
	): ?string {
		foreach ( array( 'mapping', 'revision', 'host', 'target', 'activation', 'ssl_state', 'challenge', 'expires' ) as $key ) {
			if ( ! isset( $payload[ $key ] ) ) {
				return 'malformed';
			}
		}

		// Recomputed from the values presented, then compared in constant time.
		// A payload altered anywhere — host, mapping, revision, expiry — changes
		// the signature, so there is no field an attacker can move on its own.
		$expected = self::sign(
			array(
				'mapping'    => (int) $payload['mapping'],
				'revision'   => (int) $payload['revision'],
				'host'       => (string) $payload['host'],
				'target'     => (string) $payload['target'],
				'activation' => (string) $payload['activation'],
				'ssl_state'  => (string) $payload['ssl_state'],
				'challenge'  => (string) $payload['challenge'],
				'expires'    => (int) $payload['expires'],
			)
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			return 'signature';
		}

		if ( (int) $payload['expires'] <= time() ) {
			return 'expired';
		}

		if ( ! hash_equals( $expected_challenge, (string) $payload['challenge'] ) ) {
			return 'challenge';
		}

		if ( (int) $payload['mapping'] !== $mapping->id ) {
			return 'wrong_mapping';
		}

		if ( ! hash_equals( $mapping->host, (string) $payload['host'] ) ) {
			return 'wrong_host';
		}

		// The row must not have moved since the probe ran. A proof about an
		// earlier revision describes a domain that no longer exists in that form.
		if ( (int) $payload['revision'] !== $mapping->revision ) {
			return 'stale_revision';
		}

		return null;
	}

	/**
	 * @param array<string, string|int> $payload
	 */
	private static function sign( array $payload ): string {
		ksort( $payload );

		return hash_hmac(
			'sha256',
			(string) wp_json_encode( $payload ),
			// Site salts, never sent anywhere. Only this installation can sign.
			wp_salt( 'secure_auth' ) . self::CONTEXT
		);
	}

	/** @return array<string, string|int> */
	private static function payload( Mapping $mapping, string $challenge, string $host, int $expires ): array {
		return array(
			'mapping'    => $mapping->id,
			'revision'   => $mapping->revision,
			'host'       => $host,
			'target'     => OriginConfirmation::target_identity( $mapping ),
			'activation' => $mapping->activation_state->value,
			'ssl_state'  => $mapping->ssl_state->value,
			'challenge'  => $challenge,
			'expires'    => $expires,
		);
	}
}
