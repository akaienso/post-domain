<?php
declare( strict_types = 1 );

namespace PostDomain\Application;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\InvalidMapping;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\MutationInProgress;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\StaleRevision;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Errors;
use PostDomain\Rest\SslServices;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

/**
 * The operator actions, once, for both surfaces.
 *
 * REST and the admin screen are two ways of asking for the same thing. When the
 * rules live in the REST controller, an admin screen either reimplements them —
 * and drifts — or bypasses them. Everything that decides whether an action is
 * allowed lives here; the surfaces only translate a request into a call and a
 * result into a response or a notice.
 *
 * Nothing here weakens what it moved: the same lease checks, the same alias
 * rules, the same durable deletion workflow, the same authorizers behind
 * SslServices.
 */
final class MappingCommands {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly SslServices $ssl
	) {}

	public static function production( MappingRepository $repo ): self {
		return new self( $repo, SslServices::production() );
	}

	/**
	 * Optimistic concurrency for callers that do not carry an ETag.
	 *
	 * The admin screen renders a revision into its forms; if the row moved since
	 * that page was drawn, the action is refused rather than applied to state the
	 * operator never saw. REST expresses the same rule through If-Match.
	 */
	public function at_revision( Mapping $mapping, ?int $expected ): ?CommandResult {
		if ( null === $expected ) {
			return CommandResult::refused(
				Errors::PRECONDITION_REQUIRED,
				'This action needs to say which version of the mapping it was based on.',
				428
			);
		}

		if ( $expected !== $mapping->revision ) {
			return CommandResult::refused(
				Errors::PRECONDITION_FAILED,
				'This mapping changed after the page was loaded. Reload it and try again.',
				412
			);
		}

		return null;
	}

	/** A provider mutation in any phase, expired or not, owns the row (spec §15.2). */
	private function unleased( Mapping $mapping ): ?CommandResult {
		if ( null !== $mapping->ssl_mutation_token ) {
			return CommandResult::refused(
				Errors::MUTATION_IN_PROGRESS,
				'A certificate operation is still running for this domain. Try again once it finishes.',
				409
			);
		}

		return null;
	}

	/**
	 * Named `create_mapping`, not `create`: `MutationGateTest` proves lexically
	 * that no `->create(` outside the gate can reach a driver, and a command
	 * called `create` would be indistinguishable from one that does. The
	 * invariant is worth more than the shorter name.
	 */
	public function create_mapping( string $raw_host, ?int $alias_of, ?int $post_id ): CommandResult {
		if ( str_contains( $raw_host, '*' ) ) {
			return CommandResult::refused(
				Errors::HOST_WILDCARD,
				'Wildcard domains are not supported. Map each hostname on its own.',
				400
			);
		}

		$authority = ( new AuthorityParser() )->parse( $raw_host );

		if ( null === $authority ) {
			return CommandResult::refused(
				Errors::HOST_MALFORMED_AUTHORITY,
				'That does not look like a domain name. Enter a hostname such as club.example.org.',
				400
			);
		}

		$host = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $host ) {
			return CommandResult::refused(
				Errors::HOST_INVALID,
				'That domain name cannot be used. Check it for stray characters.',
				400
			);
		}

		if ( null !== $this->repo->by_host( $host ) ) {
			return CommandResult::refused(
				Errors::HOST_EXISTS,
				'That domain is already mapped.',
				409
			);
		}

		// `pd_txt_record_label` runs only at create and rotate, and its validated
		// result is what gets persisted (spec §13.1). The provisional row exists
		// so the filter sees the mapping it is deciding a label for.
		$label = Challenge::label_for(
			new Mapping(
				0,
				$host,
				$alias_of,
				null,
				1,
				VerificationState::UNVERIFIED,
				ActivationState::INACTIVE,
				SslState::NONE,
				null,
				str_repeat( '0', 32 ),
				Challenge::DEFAULT_LABEL
			)
		);

		if ( strlen( $host ) > Challenge::max_host_length( $label ) ) {
			return CommandResult::refused(
				Errors::HOST_TOO_LONG,
				sprintf(
					'That domain is too long to hold a verification record: %d characters at most with this label.',
					Challenge::max_host_length( $label )
				),
				400
			);
		}

		if ( null === $alias_of ) {
			if ( null === $post_id || null === get_post( $post_id ) ) {
				return CommandResult::refused(
					Errors::POST_INVALID,
					'Choose the page or post this domain should show.',
					400
				);
			}
		}

		try {
			$mapping = $this->repo->save(
				new Mapping(
					0,
					$host,
					$alias_of,
					null === $alias_of ? $post_id : null,
					1,
					VerificationState::UNVERIFIED,
					ActivationState::INACTIVE,
					SslState::NONE,
					null,
					Challenge::token(),
					$label
				)
			);
		} catch ( InvalidMapping $e ) {
			return CommandResult::refused( Errors::ALIAS_CHAIN, $e->getMessage(), 400 );
		}

		return CommandResult::ok( 201, $mapping );
	}

	public function set_activation( Mapping $mapping, ActivationState $to, ?int $post_id = null ): CommandResult {
		if ( null !== $post_id && $mapping->is_alias() ) {
			return CommandResult::refused(
				Errors::ALIAS_NO_TARGET,
				'An alias always points where its canonical domain points.',
				400
			);
		}

		$leased = $this->unleased( $mapping );

		if ( null !== $leased ) {
			return $leased;
		}

		try {
			$saved = $this->repo->save(
				new Mapping(
					$mapping->id,
					$mapping->host,
					$mapping->alias_of,
					null === $post_id ? $mapping->post_id : $post_id,
					$mapping->revision,
					$mapping->verification_state,
					$to,
					$mapping->ssl_state,
					$mapping->integrity_error,
					$mapping->challenge,
					$mapping->challenge_label,
					$mapping->ssl_ownership_origin,
					$mapping->ssl_owner_installation_id,
					$mapping->ssl_provider,
					$mapping->ssl_provider_environment,
					$mapping->ssl_ref,
					$mapping->ssl_method
				)
			);
		} catch ( MutationInProgress $e ) {
			unset( $e );

			return CommandResult::refused(
				Errors::MUTATION_IN_PROGRESS,
				'A certificate operation started while this was being saved. Try again once it finishes.',
				409
			);
		} catch ( StaleRevision $e ) {
			unset( $e );

			return CommandResult::refused(
				Errors::PRECONDITION_FAILED,
				'This mapping changed after the page was loaded. Reload it and try again.',
				412
			);
		}

		return CommandResult::ok( 200, $saved );
	}

	/**
	 * Schedules the probe rather than running it, so a slow or hostile resolver
	 * cannot hold a request open (spec §15.2). The rate limit is per mapping.
	 */
	public function verify_now( Mapping $mapping ): CommandResult {
		$key = 'pd_verify_rate_' . $mapping->id;

		if ( false !== get_transient( $key ) ) {
			return CommandResult::refused(
				Errors::RATE_LIMITED,
				'This domain was checked less than a minute ago. Wait a moment and try again.',
				429
			);
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );
		wp_schedule_single_event( time(), 'pd_verify_now', array( $mapping->id ) );

		return CommandResult::ok( 202, $mapping );
	}

	public function rotate_challenge( Mapping $mapping ): CommandResult {
		$leased = $this->unleased( $mapping );

		if ( null !== $leased ) {
			return $leased;
		}

		$label = Challenge::label_for( $mapping );

		if ( null === Challenge::record_name( $label, $mapping->host ) ) {
			return CommandResult::refused(
				Errors::HOST_TOO_LONG,
				'The verification record name for this domain would be invalid.',
				400
			);
		}

		$rotated = $this->repo->save(
			new Mapping(
				$mapping->id,
				$mapping->host,
				$mapping->alias_of,
				$mapping->post_id,
				$mapping->revision,
				VerificationState::UNVERIFIED,
				$mapping->activation_state,
				$mapping->ssl_state,
				$mapping->integrity_error,
				Challenge::token(),
				$label,
				// The durable binding is untouched by a challenge rotation, and it
				// moves as one: carrying it through is what keeps the row valid.
				$mapping->ssl_ownership_origin,
				$mapping->ssl_owner_installation_id,
				$mapping->ssl_provider,
				$mapping->ssl_provider_environment,
				$mapping->ssl_ref,
				$mapping->ssl_method
			)
		);

		return CommandResult::ok( 200, $rotated );
	}

	public function delete( Mapping $mapping ): CommandResult {
		if ( array() !== ( new AliasResolver( $this->repo ) )->aliases_of( $mapping->id ) ) {
			return CommandResult::refused(
				Errors::ALIAS_IN_USE,
				'Other domains alias this one. Remove them first.',
				409
			);
		}

		// The durable workflow decides: local delete now, or pending_removal.
		if ( ! $this->ssl->delete->request( $mapping ) ) {
			return CommandResult::refused(
				Errors::CONFLICT,
				'This mapping changed, or a certificate operation is running. Reload and try again.',
				409
			);
		}

		$after = $this->repo->by_id( $mapping->id );

		return CommandResult::ok( null === $after ? 204 : 202, $after );
	}

	public function provision_ssl( Mapping $mapping ): CommandResult {
		return $this->from_mutation( $this->ssl->create->provision( $mapping ), $mapping );
	}

	public function remove_ssl( Mapping $mapping ): CommandResult {
		$outcome = $this->ssl->remove_resource->process( $mapping );

		return match ( $outcome ) {
			'removed'        => CommandResult::ok( 200, $this->repo->by_id( $mapping->id ) ),
			'fenced'         => CommandResult::refused(
				Errors::FENCED,
				'Another process took over this removal. Reload the mapping before retrying.',
				409
			),
			'refused'        => CommandResult::refused(
				Errors::MUTATION_UNAUTHORIZED,
				'The certificate could not be removed: the checks that authorize it did not pass.',
				409
			),
			'scope_conflict' => CommandResult::refused(
				Errors::CONFLICT,
				'This mapping is already being deleted; its certificate goes with it.',
				409
			),
			'deferred'       => CommandResult::refused(
				Errors::FINALIZATION_FAILED,
				'The certificate was removed at the provider, but that is not confirmed locally yet. Reload shortly.',
				409
			),
			default          => CommandResult::ok( 202, $this->repo->by_id( $mapping->id ) ),
		};
	}

	/** Translates a provider mutation into the shared vocabulary. */
	private function from_mutation( object $result, Mapping $mapping ): CommandResult {
		/** @var \PostDomain\Ssl\MutationResult $result */
		if ( MutationDisposition::COMMITTED === $result->disposition ) {
			return CommandResult::ok( 202, $this->repo->by_id( $mapping->id ) );
		}

		if ( MutationDisposition::FENCED === $result->disposition ) {
			return CommandResult::refused(
				Errors::FENCED,
				'Another process took over this certificate operation. Reload the mapping before retrying.',
				409
			);
		}

		if ( MutationDisposition::AMBIGUOUS_RETAINED === $result->disposition ) {
			return CommandResult::refused(
				Errors::OUTCOME_AMBIGUOUS,
				'The certificate provider did not confirm the change. Nothing was assumed; check again shortly.',
				409
			);
		}

		if ( MutationDisposition::CONFIRMED_NOT_PERSISTED === $result->disposition ) {
			return CommandResult::refused(
				Errors::FINALIZATION_FAILED,
				'The provider accepted the change but it is not recorded locally yet. Reload shortly.',
				409
			);
		}

		return CommandResult::refused(
			Errors::MUTATION_UNAUTHORIZED,
			self::explain_refusal( $result->refusal?->precondition ),
			409
		);
	}

	/**
	 * Plain language for a refusal. The precondition name is an internal term of
	 * art; an operator needs to know what to do about it.
	 */
	public static function explain_refusal( ?string $precondition ): string {
		return match ( $precondition ) {
			'not_verified'                 => 'This domain has not been verified yet. Publish its TXT record and check verification first.',
			'not_active'                   => 'Activate this domain before requesting a certificate.',
			'fresh_proof_failed'           => 'The verification record could not be confirmed just now. Check the DNS record and try again.',
			'fresh_proof_transient'        => 'The verification record could not be reached just now. Try again shortly.',
			'no_ownership_authority'       => 'This installation does not own the certificate for this domain.',
			'provider_environment_changed' => 'The certificate provider settings changed since this domain was set up. Restore them, or resolve the mismatch, before continuing.',
			'identity_not_confirmed'       => 'The provider could not confirm what exists for this domain. Nothing was changed.',
			'conflicting_marker'           => 'A certificate for this domain belongs to another installation.',
			'lease_unavailable'            => 'A certificate operation is already running for this domain.',
			'driver_not_registered'        => 'No certificate provider is configured. Choose one in the settings above.',
			'method_unsupported'           => 'The configured provider does not support that validation method.',
			'cooldown'                     => 'The certificate provider asked us to wait before trying again.',
			default                        => 'The request was refused before anything was changed at the certificate provider.',
		};
	}
}
