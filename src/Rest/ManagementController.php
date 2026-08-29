<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\InvalidMapping;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationDisposition;
use PostDomain\Ssl\MutationResult;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

final class ManagementController {

	public function __construct(
		private readonly MappingRepository $repo,
		private readonly SslServices $ssl
	) {}

	public function register(): void {
		$this->register_domains();
		$this->register_domain();
		$this->register_verification();
		$this->register_ssl();
		$this->register_environment();
	}

	private function permission(): callable {
		return array( Guard::class, 'may_manage' );
	}

	private function register_domains(): void {
		register_rest_route(
			Errors::NS,
			'/domains',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->permission(),
				),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$compute = 'serving' === $request->get_param( '_compute' );
		$rows    = array();

		foreach ( $this->repo->all() as $mapping ) {
			$rows[] = $compute
				? MappingSerializer::resource( $mapping )
				: MappingSerializer::row( $mapping );
		}

		return new \WP_REST_Response( $rows, 200 );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		$raw   = (string) $request->get_param( 'host' );
		$alias = $request->get_param( 'alias_of' );

		if ( str_contains( $raw, '*' ) ) {
			return self::error( Errors::HOST_WILDCARD, 'Wildcard hosts are out of scope.', 400 );
		}

		$authority = ( new AuthorityParser() )->parse( $raw );

		if ( null === $authority ) {
			return self::error( Errors::HOST_MALFORMED_AUTHORITY, 'That host is not a valid authority.', 400 );
		}

		$host = ( new HostNormalizer( new IdnaNormalizer() ) )->normalize( $authority );

		if ( null === $host ) {
			return self::error( Errors::HOST_INVALID, 'That host cannot be normalized.', 400 );
		}

		if ( null !== $this->repo->by_host( $host ) ) {
			return self::error( Errors::HOST_EXISTS, 'That host is already mapped.', 409 );
		}

		// `pd_txt_record_label` runs only at create and rotate, and its validated
		// result is what gets persisted (spec §13.1). The provisional row exists
		// so the filter sees the mapping it is deciding a label for.
		$label = Challenge::label_for(
			new Mapping(
				0,
				$host,
				null === $alias ? null : (int) $alias,
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
			return self::error(
				Errors::HOST_TOO_LONG,
				sprintf(
					'The composed TXT record name would exceed 253 bytes; this label permits %d bytes of host.',
					Challenge::max_host_length( $label )
				),
				400
			);
		}

		$post_id = null;

		if ( null === $alias ) {
			$post_id = (int) $request->get_param( 'post_id' );

			if ( null === get_post( $post_id ) ) {
				return self::error( Errors::POST_INVALID, 'That post does not exist.', 400 );
			}
		}

		try {
			$mapping = $this->repo->save(
				new Mapping(
					0,
					$host,
					null === $alias ? null : (int) $alias,
					$post_id,
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
			return self::error( Errors::ALIAS_CHAIN, $e->getMessage(), 400 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 201 );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}

	private function register_domain(): void {
		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->permission(),
				),
			)
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), 200 );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		$post_id = $request->get_param( 'post_id' );

		if ( null !== $post_id && $mapping->is_alias() ) {
			return self::error( Errors::ALIAS_NO_TARGET, 'An alias derives its target from its canonical row.', 400 );
		}

		$activation = $request->get_param( 'activation_state' );

		if ( null !== $activation && null === ActivationState::tryFrom( (string) $activation ) ) {
			return self::error( Errors::CONFLICT, 'That is not an activation state.', 400 );
		}

		// verification_state and ssl_state are copied from the stored row, never
		// from the request: they are outcomes, not settings.
		$updated = new Mapping(
			$mapping->id,
			$mapping->host,
			$mapping->alias_of,
			null === $post_id ? $mapping->post_id : (int) $post_id,
			$mapping->revision,
			$mapping->verification_state,
			null === $activation ? $mapping->activation_state : ActivationState::from( (string) $activation ),
			$mapping->ssl_state,
			$mapping->integrity_error,
			$mapping->challenge,
			$mapping->challenge_label,
			$mapping->ssl_ownership_origin,
			$mapping->ssl_owner_installation_id,
			$mapping->ssl_provider,
			// The durable binding is five columns that move as one; dropping the
			// environment here would shift ssl_ref into its slot and produce
			// exactly the half-written binding assert_valid() forbids.
			$mapping->ssl_provider_environment,
			$mapping->ssl_ref,
			$mapping->ssl_method
		);

		$saved    = $this->repo->save( $updated );
		$response = new \WP_REST_Response( MappingSerializer::resource( $saved ), 200 );
		$response->header( 'ETag', Guard::etag( $saved ) );

		return $response;
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		if ( array() !== ( new AliasResolver( $this->repo ) )->aliases_of( $mapping->id ) ) {
			return self::error( Errors::ALIAS_IN_USE, 'Other mappings alias this one.', 409 );
		}

		// The durable workflow decides: local delete now, or pending_removal.
		if ( ! $this->ssl->delete->request( $mapping ) ) {
			return self::error( Errors::CONFLICT, 'The mapping changed or a mutation is in progress.', 409 );
		}

		$after = $this->repo->by_id( $mapping->id );

		return null === $after
			? new \WP_REST_Response( null, 204 )
			: new \WP_REST_Response( MappingSerializer::resource( $after ), 202 );
	}

	private function register_verification(): void {
		foreach (
			array(
				'/domains/(?P<id>[\d]+)/verify'    => 'verify',
				'/domains/(?P<id>[\d]+)/challenge' => 'rotate_challenge',
			) as $route => $handler
		) {
			register_rest_route(
				Errors::NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $handler ),
					'permission_callback' => $this->permission(),
				)
			);
		}
	}

	public function verify( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$key = 'pd_verify_rate_' . $mapping->id;

		if ( false !== get_transient( $key ) ) {
			return self::error( Errors::RATE_LIMITED, 'Verification was requested less than a minute ago.', 429 );
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS );

		// The probe is scheduled; the response reports state, it does not claim it.
		wp_schedule_single_event( time(), 'pd_verify_now', array( $mapping->id ) );

		return new \WP_REST_Response( MappingSerializer::resource( $mapping ), 202 );
	}

	public function rotate_challenge( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		// Any lease at all, including an expired one awaiting recovery (spec §15.2).
		if ( null !== $mapping->ssl_mutation_token ) {
			return self::error( Errors::MUTATION_IN_PROGRESS, 'A provider mutation is in progress.', 409 );
		}

		// Rotation is the other point at which `pd_txt_record_label` runs and its
		// validated result is persisted (spec §13.1).
		$label = Challenge::label_for( $mapping );

		if ( null === Challenge::record_name( $label, $mapping->host ) ) {
			return self::error( Errors::HOST_TOO_LONG, 'The composed TXT record name would be invalid.', 400 );
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

		$data         = MappingSerializer::resource( $rotated );
		$data['note'] = 'The challenge was rotated; verification is now unverified and the new record must be published.';

		return new \WP_REST_Response( $data, 200 );
	}

	private function register_ssl(): void {
		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => $this->permission(),
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/ssl',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'provision_ssl' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'change_ssl_method' ),
					'permission_callback' => $this->permission(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_ssl' ),
					'permission_callback' => $this->permission(),
				),
			)
		);

		register_rest_route(
			Errors::NS,
			'/domains/(?P<id>[\d]+)/ssl/adopt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'adopt_ssl' ),
				'permission_callback' => $this->permission(),
			)
		);
	}

	public function plan( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$driver = $this->ssl->driver_for( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			// The refusal carries its own accurately-labelled fields; nothing here
			// reads an environment out of a driver-id slot.
			if ( 'provider_environment_changed' === $driver->reason ) {
				$response = self::error( Errors::ENVIRONMENT_DRIFTED, $driver->detail(), 409 );
				$data     = $response->get_data();

				$data['data']['driver_id']              = $driver->driver_id;
				$data['data']['expected_environment']   = $driver->expected_environment;
				$data['data']['configured_environment'] = $driver->configured_environment;

				$response->set_data( $data );

				return $response;
			}

			return self::error(
				'ssl_not_configured' === $driver->reason ? Errors::SSL_NOT_CONFIGURED : Errors::NO_DRIVER,
				$driver->detail(),
				409
			);
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return self::error( Errors::HOST_TOO_LONG, 'The composed TXT record name is invalid.', 400 );
		}

		$context = SslResourceContext::from_mapping(
			$mapping,
			Environment::installation_id(),
			$name,
			$driver->id()
		);

		$plan = $driver->validation_plan( $context, null );

		return new \WP_REST_Response(
			array(
				'dns'      => $plan->dns,
				'http'     => $plan->http,
				'manual'   => $plan->manual,
				'pending'  => $plan->pending,
				'blockers' => $plan->blockers,
			),
			200
		);
	}

	public function provision_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		return $this->ssl_outcome( $this->ssl->create->provision( $mapping ), $mapping->id, 202 );
	}

	public function change_ssl_method( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		return $this->ssl_outcome(
			$this->ssl->method->change( $mapping, (string) $request->get_param( 'method' ) ),
			$mapping->id,
			200
		);
	}

	public function adopt_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		$result = $this->ssl->adopt->take_ownership(
			$mapping,
			array(
				'confirm'                 => true === $request->get_param( 'confirm' ),
				'override_foreign_marker' => true === $request->get_param( 'override_foreign_marker' ),
			)
		);

		return $this->ssl_outcome( $result, $mapping->id, 200 );
	}

	public function remove_ssl( \WP_REST_Request $request ): \WP_REST_Response {
		$mapping = $this->repo->by_id( (int) $request->get_param( 'id' ) );

		if ( null === $mapping ) {
			return self::error( 'rest_no_route', 'No such mapping.', 404 );
		}

		$precondition = Guard::check_precondition( $request, $mapping );

		if ( $precondition instanceof \WP_Error ) {
			return self::from_wp_error( $precondition );
		}

		// Deletion reports its own vocabulary because a removal can legitimately
		// remain pending at the provider for a long time.
		//
		// The SSL-resource removal, not the mapping deletion: this route removes
		// a certificate, and the mapping — host, post binding, verification
		// state, aliases — outlives it. DELETE /domains/{id} is the only normal
		// route that deletes a mapping row (spec 14.15).
		$outcome = $this->ssl->remove_resource->process( $mapping );

		if ( 'fenced' === $outcome ) {
			return self::error(
				Errors::FENCED,
				'Another worker took over this removal; re-read the mapping before retrying.',
				409
			);
		}

		if ( 'scope_conflict' === $outcome ) {
			return self::error(
				Errors::CONFLICT,
				'This mapping is already awaiting deletion; its certificate goes with it.',
				409
			);
		}

		if ( 'refused' === $outcome ) {
			return self::error( Errors::MUTATION_UNAUTHORIZED, 'The removal was refused before any provider call.', 409 );
		}

		if ( 'deferred' === $outcome ) {
			// The local outcome could not be established. Re-reading the row here
			// would read the same connection that does not know, so this answers
			// 409 rather than a 204 it cannot stand behind.
			return self::error(
				Errors::FINALIZATION_FAILED,
				'The provider removed the certificate; whether that was recorded locally is not yet established. Re-read the mapping shortly.',
				409
			);
		}

		$after = $this->repo->by_id( $mapping->id );

		if ( null === $after ) {
			// Nothing here deletes the row, so its absence means someone else
			// did — and this response cannot describe a mapping that is gone.
			return new \WP_REST_Response( null, 204 );
		}

		// A confirmed removal is complete: the resource is gone and the mapping
		// remains, unbound. Anything else is still outstanding at the provider.
		return new \WP_REST_Response(
			MappingSerializer::resource( $after ) + array( 'removal' => $outcome ),
			'removed' === $outcome ? 200 : 202
		);
	}

	/**
	 * One translation for every SSL operation. The five dispositions are five
	 * different answers, and collapsing them would let a discarded mutation be
	 * reported as a successful one.
	 */
	private function ssl_outcome(
		MutationResult $result,
		int $mapping_id,
		int $success_status
	): \WP_REST_Response {
		if ( MutationDisposition::REFUSED === $result->disposition ) {
			$refusal = $result->refusal;
			$code    = match ( $refusal?->precondition ) {
				'confirmation_required'            => Errors::CONFIRMATION_REQUIRED,
				'method_unsupported'               => Errors::METHOD_UNSUPPORTED,
				'environment_unresolved'           => Errors::ENVIRONMENT_UNRESOLVED,
				'lease_unavailable'                => Errors::MUTATION_IN_PROGRESS,
				'ssl_not_configured'               => Errors::SSL_NOT_CONFIGURED,
				'driver_not_registered'            => Errors::NO_DRIVER,
				'provider_environment_changed'     => Errors::ENVIRONMENT_DRIFTED,
				'unowned_resource',
				'foreign_marker_override_required' => Errors::UNOWNED_RESOURCE,
				default                            => Errors::MUTATION_UNAUTHORIZED,
			};

			// Spec 15.3 fixes the status of pd_method_unsupported at 400: asking
			// for a method the driver does not offer is a bad request, not a
			// conflict with the row's current state.
			$status = 409;

			if ( Errors::METHOD_UNSUPPORTED === $code ) {
				$status = 400;
			} elseif ( true === $refusal?->transient ) {
				$status = 503;
			}

			$response = self::error( $code, 'The operation was refused before any provider call.', $status );
			$data     = $response->get_data();

			$data['data']['precondition'] = $refusal?->precondition;
			$response->set_data( $data );

			return $response;
		}

		// Recovery took the row while the provider call was outstanding. Nothing
		// was written and nothing will be retried here, so this is not a success.
		if ( MutationDisposition::FENCED === $result->disposition ) {
			return self::error(
				Errors::FENCED,
				'Another worker took over this mutation; re-read the mapping before retrying.',
				409
			);
		}

		if ( MutationDisposition::CONFIRMED_NOT_PERSISTED === $result->disposition ) {
			return self::error(
				Errors::FINALIZATION_FAILED,
				'The provider confirmed the change but it could not be recorded locally; reconciliation will settle it.',
				409
			);
		}

		if ( MutationDisposition::AMBIGUOUS_RETAINED === $result->disposition ) {
			$mapping = $this->repo->by_id( $mapping_id );

			return new \WP_REST_Response(
				null === $mapping
					? array(
						'code'    => Errors::OUTCOME_AMBIGUOUS,
						'message' => $result->note,
					)
					: MappingSerializer::resource( $mapping ) + array( 'note' => $result->note ),
				202
			);
		}

		$mapping = $this->repo->by_id( $mapping_id );

		if ( null === $mapping ) {
			return new \WP_REST_Response( null, 204 );
		}

		$response = new \WP_REST_Response( MappingSerializer::resource( $mapping ), $success_status );
		$response->header( 'ETag', Guard::etag( $mapping ) );

		return $response;
	}

	private function register_environment(): void {
		register_rest_route(
			Errors::NS,
			'/environment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'environment' ),
				'permission_callback' => $this->permission(),
			)
		);

		register_rest_route(
			Errors::NS,
			'/environment/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve_environment' ),
				'permission_callback' => $this->permission(),
			)
		);
	}

	public function environment( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$mismatch = get_option( 'pd_environment_mismatch', null );

		// The installation id is an ownership secret in all but name: it is the
		// value a provider marker is matched against. It is never published.
		return new \WP_REST_Response(
			array(
				'blocked'      => Environment::is_blocked(),
				'mismatch'     => is_array( $mismatch ) ? $mismatch : null,
				'primary_host' => Environment::primary_host(),
			),
			200
		);
	}

	public function resolve_environment( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Environment::is_blocked() ) {
			return self::error( Errors::CONFLICT, 'There is nothing to resolve.', 409 );
		}

		// Spec 15.2 names the field `choice`; `resolution` is accepted as well
		// because it is the name the admin screen and the plan example both use.
		$choice = $request->get_param( 'choice' ) ?? $request->get_param( 'resolution' );

		if ( 'restore' === $choice ) {
			Environment::resolve_as_restore();
		} elseif ( 'clone' === $choice ) {
			Environment::resolve_as_clone();
		} else {
			return self::error(
				Errors::ENVIRONMENT_UNRESOLVED,
				'Answer with "restore" (same site, new address) or "clone" (a copy).',
				400
			);
		}

		return $this->environment( $request );
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	private static function from_wp_error( \WP_Error $error ): \WP_REST_Response {
		$status = (int) ( $error->get_error_data()['status'] ?? 400 );

		return self::error( (string) $error->get_error_code(), $error->get_error_message(), $status );
	}
}
