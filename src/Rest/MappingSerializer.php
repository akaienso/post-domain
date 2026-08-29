<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\Environment;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

final class MappingSerializer {

	/** @return array<string, mixed> */
	public static function row( Mapping $mapping ): array {
		return array(
			'id'           => $mapping->id,
			'revision'     => $mapping->revision,
			'host'         => $mapping->host,
			'host_display' => ( new IdnaNormalizer() )->to_display( $mapping->host ),
			'alias_of'     => $mapping->alias_of,
			'post_id'      => $mapping->post_id,
			'verification' => array(
				'state' => $mapping->verification_state->value,
			),
			'activation'   => array( 'state' => $mapping->activation_state->value ),
			'ssl'          => array( 'state' => $mapping->ssl_state->value ),
		);
	}

	/** @return array<string, mixed> */
	public static function resource( Mapping $mapping ): array {
		$resource = self::row( $mapping );

		$resource['target']        = self::target( $mapping );
		$resource['dns_challenge'] = array(
			'type'  => 'TXT',
			'name'  => Challenge::record_name( $mapping->challenge_label, $mapping->host ),
			'value' => Challenge::expected_value( $mapping->challenge ),
			'ttl'   => 300,
		);

		$resource['ssl'] = array(
			'state'                      => $mapping->ssl_state->value,
			'provider'                   => $mapping->ssl_provider,
			// Where the resource lives, which is not the same fact as where a
			// mutation currently in flight is going.
			'provider_environment'       => $mapping->ssl_provider_environment,
			'environment_reachable'      => null === $mapping->ssl_ref
				|| ! ( \PostDomain\Ssl\BoundResource::driver_for( $mapping ) instanceof \PostDomain\Ssl\DriverUnavailable ),
			'ownership_origin'           => $mapping->ssl_ownership_origin?->value,
			'owned_by_this_installation' => null !== $mapping->ssl_ownership_origin
				&& Environment::installation_id() === $mapping->ssl_owner_installation_id,
			'method'                     => $mapping->ssl_method,
			'mutation_in_progress'       => null === $mapping->ssl_mutation_kind
				? null
				: array(
					'kind'        => $mapping->ssl_mutation_kind->value,
					'phase'       => $mapping->ssl_mutation_phase?->value,
					'expires_at'  => $mapping->ssl_mutation_expires_at,
					// The environment identity is a non-secret name an operator
					// compares against their provider console. The lease token
					// is not here, and neither is any credential.
					'driver'      => $mapping->ssl_mutation_driver,
					'environment' => $mapping->ssl_mutation_environment,
				),
		);

		$resource['serving'] = self::serving( $mapping );

		return $resource;
	}

	/** @return array{state: string, reason: string|null, blocked_by: array{id: int, host: string}|null} */
	private static function serving( Mapping $mapping ): array {
		$repo    = new DbRepository();
		$aliases = new AliasResolver( $repo );

		$own = self::blocker_for( $mapping );

		if ( null !== $own ) {
			return array(
				'state'      => $own,
				'reason'     => null,
				'blocked_by' => null,
			);
		}

		$canonical = $aliases->canonical_for( $mapping );

		if ( null !== $canonical && $canonical->id !== $mapping->id ) {
			$parent = self::blocker_for( $canonical );

			if ( null !== $parent ) {
				return array(
					'state'      => $parent,
					'reason'     => 'canonical mapping is not serving',
					'blocked_by' => array(
						'id'   => $canonical->id,
						'host' => $canonical->host,
					),
				);
			}
		}

		return array(
			'state'      => 'serving',
			'reason'     => null,
			'blocked_by' => null,
		);
	}

	private static function blocker_for( Mapping $mapping ): ?string {
		if ( VerificationState::VERIFIED !== $mapping->verification_state ) {
			return 'unverified';
		}

		if ( ActivationState::ACTIVE !== $mapping->activation_state ) {
			return 'inactive';
		}

		if ( ! (bool) apply_filters( 'pd_mapping_is_active', true, $mapping, null ) ) {
			return 'vetoed';
		}

		if ( null !== $mapping->integrity_error ) {
			return 'broken';
		}

		$target = $mapping->is_alias() ? null : get_post( (int) $mapping->post_id );

		if ( ! $mapping->is_alias() && ( null === $target || 'publish' !== $target->post_status ) ) {
			return 'broken';
		}

		return null;
	}

	/** @return array<string, mixed> */
	private static function target( Mapping $mapping ): array {
		$repo      = new DbRepository();
		$aliases   = new AliasResolver( $repo );
		$target_id = $aliases->effective_post_id( $mapping );
		$post      = null === $target_id ? null : get_post( $target_id );

		if ( null === $post ) {
			return array(
				'id'        => $target_id,
				'post_type' => null,
				'rest_base' => null,
				'rest_link' => null,
				'edit_link' => null,
				'derived'   => $mapping->is_alias(),
			);
		}

		$type      = get_post_type_object( $post->post_type );
		$rest_base = null;
		$rest_link = null;

		if ( null !== $type && true === $type->show_in_rest ) {
			$rest_base = is_string( $type->rest_base ) && '' !== $type->rest_base
				? $type->rest_base
				: $post->post_type;
			$namespace = is_string( $type->rest_namespace ) && '' !== $type->rest_namespace
				? $type->rest_namespace
				: 'wp/v2';
			$rest_link = rest_url( $namespace . '/' . $rest_base . '/' . $post->ID );
		}

		return array(
			'id'        => $post->ID,
			'post_type' => $post->post_type,
			'rest_base' => $rest_base,
			'rest_link' => $rest_link,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'derived'   => $mapping->is_alias(),
		);
	}
}
