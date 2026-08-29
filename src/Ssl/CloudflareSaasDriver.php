<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\HttpClient;
use PostDomain\Contracts\SslDriver;
use PostDomain\Mapping\SslState;
use PostDomain\Support\HttpResponse;
use PostDomain\Support\PublicSuffix;

final class CloudflareSaasDriver implements SslDriver {

	private const API = 'https://api.cloudflare.com/client/v4';

	private const ERROR_UNAVAILABLE_METADATA = 1413;

	private const ERROR_DUPLICATE = 1406;

	private MarkerSupport $marker_support = MarkerSupport::UNKNOWN;

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $token,
		private readonly string $zone_id,
		private readonly string $cname_target
	) {}

	public function id(): string {
		return 'cloudflare-saas';
	}

	/**
	 * Custom hostnames live in a zone, and a zone id is globally unique and not a
	 * credential — it appears in every API path. It is therefore the right thing
	 * to bind a mutation to: a token rotated to a different account reaches a
	 * different zone id, and the same zone id always means the same resources.
	 * The API token is never part of this value.
	 */
	public function environment_id(): string {
		return 'cf-zone:' . $this->zone_id;
	}

	public function marker_support(): MarkerSupport {
		return $this->marker_support;
	}

	public function capabilities(): DriverCapabilities {
		return new DriverCapabilities(
			MarkerSupport::UNAVAILABLE !== $this->marker_support,
			array( 'http', 'txt', 'email' ),
			array() !== Credentials::apex_targets()
		);
	}

	public function status( SslResourceContext $ctx ): SslStatus {
		$response = $this->get_hostname( $ctx );

		if ( null === $response['payload'] ) {
			return $this->status_from_failure( $response['response'] );
		}

		return $this->status_from_payload( $response['payload'] );
	}

	public function identify( SslResourceContext $ctx ): IdentityResult {
		$response = $this->get_hostname( $ctx );
		$payload  = $response['payload'];

		if ( null === $payload ) {
			return new IdentityResult(
				IdentityVerdict::UNKNOWN,
				$ctx->provider_ref,
				null,
				null,
				null,
				$this->marker_support,
				false,
				$this->is_transient( $response['response'] )
			);
		}

		$observed_ref = (string) ( $payload['id'] ?? '' );
		$hostname     = (string) ( $payload['hostname'] ?? '' );
		$marker       = $this->parse_marker( $payload );

		if ( null === $ctx->provider_ref ) {
			return new IdentityResult(
				IdentityVerdict::RECOVERABLE_CREATE,
				null,
				$observed_ref,
				$hostname,
				$marker,
				$this->marker_support,
				true,
				false
			);
		}

		$verdict = ( $observed_ref === $ctx->provider_ref && $hostname === $ctx->host )
			? IdentityVerdict::MATCH
			: IdentityVerdict::MISMATCH;

		return new IdentityResult(
			$verdict,
			$ctx->provider_ref,
			$observed_ref,
			$hostname,
			$marker,
			$this->marker_support,
			true,
			false
		);
	}

	public function create( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CREATE, $ctx );

		$body = array(
			'hostname' => $ctx->host,
			'ssl'      => array(
				'method' => $ctx->requested_method ?? Credentials::ssl_method(),
				'type'   => 'dv',
			),
		);

		if ( MarkerSupport::UNAVAILABLE !== $this->marker_support ) {
			$body['custom_metadata'] = array(
				'pd_install' => $ctx->installation_id,
				'pd_mapping' => (string) $ctx->mapping_id,
			);
		}

		$response = $this->request( 'POST', '/zones/' . $this->zone_id . '/custom_hostnames', $body );

		if ( $this->has_error( $response, self::ERROR_UNAVAILABLE_METADATA ) ) {
			// Definitive rejection: nothing was created. Exactly one retry,
			// inside this execution, without the optional field.
			$this->marker_support = MarkerSupport::UNAVAILABLE;
			unset( $body['custom_metadata'] );

			$response = $this->request( 'POST', '/zones/' . $this->zone_id . '/custom_hostnames', $body );
		}

		if ( $this->has_error( $response, self::ERROR_DUPLICATE ) ) {
			// Read, never re-POST.
			$this->get_hostname( $ctx );

			return new SslStatus( SslState::NONE, null, 'duplicate_record', 'A resource already exists for this hostname.' );
		}

		$payload = $this->payload( $response );

		if ( null === $payload ) {
			return $this->status_from_failure( $response );
		}

		if ( (string) ( $payload['hostname'] ?? '' ) !== $ctx->host ) {
			return new SslStatus( SslState::FAILED, null, 'hostname_mismatch', 'The provider returned a different hostname.' );
		}

		return $this->status_from_payload( $payload );
	}

	public function adopt( SslResourceContext $ctx, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::ADOPT, $ctx );

		if ( MarkerSupport::UNAVAILABLE === $this->marker_support || null === $ctx->provider_ref ) {
			return $this->status( $ctx );
		}

		$this->request(
			'PATCH',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . $ctx->provider_ref,
			array(
				'custom_metadata' => array(
					'pd_install' => $ctx->installation_id,
					'pd_mapping' => (string) $ctx->mapping_id,
				),
			)
		);

		return $this->status( $ctx );
	}

	public function change_validation_method( SslResourceContext $ctx, string $method, ExecutionPermit $permit ): SslStatus {
		$permit->assert_for( MutationOperation::CHANGE_METHOD, $ctx );

		$this->request(
			'PATCH',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . (string) $ctx->provider_ref,
			array(
				'ssl' => array(
					'method' => $method,
					'type'   => 'dv',
				),
			)
		);

		// Persist only what a re-read confirms.
		return $this->status( $ctx );
	}

	public function remove( SslResourceContext $ctx, ExecutionPermit $permit ): RemovalResult {
		$permit->assert_for( MutationOperation::REMOVE, $ctx );

		$response = $this->request(
			'DELETE',
			'/zones/' . $this->zone_id . '/custom_hostnames/' . (string) $ctx->provider_ref,
			null
		);

		if ( 404 === $response->status ) {
			return new RemovalResult( RemovalOutcome::REMOVED, 'already_absent' );
		}

		if ( 429 === $response->status ) {
			$retry = (int) ( $response->headers['retry-after'] ?? 60 );
			Cooldown::set( $this->id(), $retry, '429' );

			return new RemovalResult( RemovalOutcome::TRANSIENT, 'rate_limited', null, $retry );
		}

		if ( $this->is_transient( $response ) ) {
			return new RemovalResult( RemovalOutcome::TRANSIENT, 'transient' );
		}

		return 200 === $response->status
			? new RemovalResult( RemovalOutcome::REMOVED )
			: new RemovalResult( RemovalOutcome::FAILED, 'provider_error' );
	}

	/** @param SslResourceContext[] $contexts */
	public function reconcile( array $contexts ): ReconcileReport {
		$statuses = array();

		foreach ( $contexts as $context ) {
			$statuses[ $context->host ] = $this->status( $context );
		}

		return new ReconcileReport( $statuses, true );
	}

	public function validation_plan( SslResourceContext $ctx, ?object $apex ): ValidationPlan {
		/** @var array<string, mixed> $payload */
		$payload = $this->get_hostname( $ctx )['payload'] ?? array();

		$capability = $apex instanceof ApexCapability
			? $apex
			: ApexCapability::validated( apply_filters( 'pd_apex_capability', $this->derive_apex(), $ctx->host, null ) );

		return CloudflareValidationPlan::build(
			$payload,
			$this->cname_target,
			$capability,
			PublicSuffix::is_apex( $ctx->host ),
			$ctx->challenge_name,
			$ctx->challenge_value
		);
	}

	private function derive_apex(): ApexCapability {
		$targets = Credentials::apex_targets();

		if ( array() === $targets ) {
			return new ApexCapability(
				ApexRouting::CNAME_FLATTENING,
				'no apex proxy targets configured; relying on CNAME flattening',
				array(),
				null,
				false
			);
		}

		return new ApexCapability(
			ApexRouting::APEX_PROXY,
			'apex proxy targets configured',
			$targets,
			Credentials::apex_provenance(),
			true
		);
	}

	/** @return array{payload: array<string, mixed>|null, response: HttpResponse} */
	private function get_hostname( SslResourceContext $ctx ): array {
		$path = null !== $ctx->provider_ref
			? '/zones/' . $this->zone_id . '/custom_hostnames/' . $ctx->provider_ref
			: '/zones/' . $this->zone_id . '/custom_hostnames?hostname=' . rawurlencode( $ctx->host );

		$response = $this->request( 'GET', $path, null );
		$payload  = $this->payload( $response );

		if ( is_array( $payload ) && isset( $payload[0] ) && is_array( $payload[0] ) ) {
			$payload = $payload[0];
		}

		return array(
			'payload'  => is_array( $payload ) ? $payload : null,
			'response' => $response,
		);
	}

	/** @param array<string, mixed> $payload */
	private function status_from_payload( array $payload ): SslStatus {
		$ssl      = is_array( $payload['ssl'] ?? null ) ? $payload['ssl'] : array();
		$combined = CloudflareStatusMap::combine(
			isset( $payload['status'] ) ? (string) $payload['status'] : null,
			isset( $ssl['status'] ) ? (string) $ssl['status'] : null
		);

		$code = CloudflareStatusMap::classify_errors(
			is_array( $payload['verification_errors'] ?? null ) ? $payload['verification_errors'] : array(),
			is_array( $ssl['validation_errors'] ?? null ) ? $ssl['validation_errors'] : array()
		);

		return new SslStatus(
			$combined['state'],
			isset( $payload['id'] ) ? (string) $payload['id'] : null,
			$combined['unknown'] ? 'unknown_provider_state' : $code,
			null,
			isset( $ssl['method'] ) ? (string) $ssl['method'] : null,
			false,
			array(
				'hostname_status'     => $payload['status'] ?? null,
				'ssl_status'          => $ssl['status'] ?? null,
				'verification_errors' => $payload['verification_errors'] ?? array(),
				'validation_errors'   => $ssl['validation_errors'] ?? array(),
			)
		);
	}

	private function status_from_failure( HttpResponse $response ): SslStatus {
		if ( $this->is_transient( $response ) ) {
			return new SslStatus( SslState::NONE, null, 'transient', 'The provider did not answer.', null, true );
		}

		return new SslStatus( SslState::FAILED, null, 'provider_error', 'The provider rejected the request.' );
	}

	private function is_transient( HttpResponse $response ): bool {
		return null !== $response->error || 0 === $response->status || $response->status >= 500 || 429 === $response->status;
	}

	/** @param array<string, mixed>|null $body */
	private function request( string $method, string $path, ?array $body ): HttpResponse {
		$opts = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$opts['body'] = (string) wp_json_encode( $body );
		}

		return $this->http->request( $method, self::API . $path, $opts );
	}

	/**
	 * The decoded `result`, which Cloudflare returns as an object for a
	 * single-resource read and as a list for a query — hence array-key, not string.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private function payload( HttpResponse $response ): ?array {
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || true !== ( $decoded['success'] ?? false ) ) {
			return null;
		}

		return is_array( $decoded['result'] ?? null ) ? $decoded['result'] : null;
	}

	private function has_error( HttpResponse $response, int $code ): bool {
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['errors'] ?? null ) ) {
			return false;
		}

		foreach ( $decoded['errors'] as $error ) {
			if ( is_array( $error ) && (int) ( $error['code'] ?? 0 ) === $code ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $payload */
	private function parse_marker( array $payload ): ?ProviderMarker {
		$metadata = $payload['custom_metadata'] ?? null;

		if ( ! is_array( $metadata ) ) {
			return null;
		}

		$this->marker_support = MarkerSupport::SUPPORTED;

		return new ProviderMarker(
			isset( $metadata['pd_install'] ) ? (string) $metadata['pd_install'] : null,
			isset( $metadata['pd_mapping'] ) ? (int) $metadata['pd_mapping'] : null,
			$metadata
		);
	}
}
