<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class CloudflareValidationPlan {

	/**
	 * @param array<string, mixed> $payload The custom hostname payload.
	 * @param string               $host    The normalised mapped hostname. Routing
	 *                                      records name this host, so it is passed
	 *                                      in rather than derived: the challenge
	 *                                      label in $core_record_name is filterable
	 *                                      and is not a hostname. The parameter is
	 *                                      optional only so the plan keeps building
	 *                                      until the one production caller is wired
	 *                                      to pass $ctx->host; the provider payload
	 *                                      is the fallback, never the challenge name.
	 */
	public static function build(
		array $payload,
		string $cname_target,
		ApexCapability $apex,
		bool $is_apex,
		string $core_record_name,
		string $core_record_value,
		string $host = ''
	): ValidationPlan {
		$dns      = array();
		$http     = array();
		$manual   = array();
		$pending  = array();
		$blockers = array();

		if ( '' === $host ) {
			$host = (string) ( $payload['hostname'] ?? '' );
		}

		/**
		 * An empty payload is what an absent provider resource looks like: the
		 * read either returned nothing or was never made. Nothing about such a
		 * resource is outstanding, so the provider-issued purposes contribute
		 * neither requirements nor a wait. Claiming records are "not yet issued"
		 * for a resource that does not exist is the root of the false
		 * "Awaiting provider" notice on a revoked mapping.
		 */
		$resource_exists = array() !== $payload;

		// Purpose 1: the plugin's own permanent challenge.
		$dns['ownership'] = array(
			new DnsRequirementSet(
				'ownership',
				'core-ownership',
				'Ownership TXT (permanent)',
				array( new DnsRecordSpec( 'TXT', $core_record_name, $core_record_value ) ),
				true,
				'core',
				false
			),
		);

		// Purpose 2: Cloudflare hostname ownership.
		$hostname_status = (string) ( $payload['status'] ?? '' );

		if ( $resource_exists && 'active' !== $hostname_status ) {
			$ownership = $payload['ownership_verification'] ?? null;
			$http_own  = $payload['ownership_verification_http'] ?? null;
			$found     = false;

			if ( is_array( $ownership ) ) {
				if ( '' === (string) ( $ownership['name'] ?? '' ) || '' === (string) ( $ownership['value'] ?? '' ) ) {
					$blockers[] = new DnsBlocker(
						'provider_record_malformed',
						'Cloudflare returned an incomplete ownership record.',
						'Re-read the custom hostname; if it persists, recreate it.',
						'cloudflare-saas'
					);
				} else {
					$dns['provider_ownership'] = array(
						new DnsRequirementSet(
							'provider_ownership',
							'cf-hostname-txt',
							'Cloudflare hostname ownership TXT',
							array(
								new DnsRecordSpec(
									strtoupper( (string) ( $ownership['type'] ?? 'TXT' ) ),
									(string) $ownership['name'],
									(string) $ownership['value']
								),
							),
							true,
							'cloudflare-saas',
							true
						),
					);
					$found                     = true;
				}
			}

			if ( is_array( $http_own )
				&& '' !== (string) ( $http_own['http_url'] ?? '' )
				&& '' !== (string) ( $http_own['http_body'] ?? '' ) ) {
				$http[] = new HttpRequirementSet(
					'provider_ownership',
					'cf-hostname-http',
					'Cloudflare hostname ownership HTTP token',
					(string) $http_own['http_url'],
					(string) $http_own['http_body'],
					'cloudflare-saas',
					true
				);
				$found  = true;
			}

			if ( ! $found && array() === $blockers ) {
				$pending[] = new ValidationPending( 'provider_ownership', 'provider_records_not_yet_issued' );
			}
		}

		// Purpose 3: certificate validation.
		$ssl        = is_array( $payload['ssl'] ?? null ) ? $payload['ssl'] : array();
		$ssl_status = (string) ( $ssl['status'] ?? '' );
		$records    = is_array( $ssl['validation_records'] ?? null ) ? $ssl['validation_records'] : array();

		if ( $resource_exists && 'active' !== $ssl_status ) {
			if ( array() === $records ) {
				$pending[] = new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' );
			}

			foreach ( $records as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				if ( '' !== (string) ( $record['txt_name'] ?? '' ) && '' !== (string) ( $record['txt_value'] ?? '' ) ) {
					$dns['ssl_validation'][] = new DnsRequirementSet(
						'ssl_validation',
						'cf-dcv-txt',
						'Certificate validation TXT',
						array(
							new DnsRecordSpec( 'TXT', (string) $record['txt_name'], (string) $record['txt_value'] ),
						),
						true,
						'cloudflare-saas'
					);

					continue;
				}

				if ( '' !== (string) ( $record['http_url'] ?? '' ) && '' !== (string) ( $record['http_body'] ?? '' ) ) {
					$http[] = new HttpRequirementSet(
						'ssl_validation',
						'cf-dcv-http',
						'Certificate validation HTTP token',
						(string) $record['http_url'],
						(string) $record['http_body'],
						'cloudflare-saas'
					);

					continue;
				}

				if ( is_array( $record['emails'] ?? null ) && array() !== $record['emails'] ) {
					$manual[] = new ManualRequirement(
						'ssl_validation',
						'cf-dcv-email',
						'Certificate validation email',
						'A person must open the approval email and follow its link. This cannot be automated.',
						array_map( 'strval', $record['emails'] ),
						'cloudflare-saas'
					);

					continue;
				}

				$blockers[] = new DnsBlocker(
					'provider_record_malformed',
					'Cloudflare returned a validation record in an unrecognised shape.',
					'Re-read the custom hostname; if it persists, change the validation method.',
					'cloudflare-saas'
				);
			}
		}

		// Purpose 4: routing. No CNAME is assumed, and no record is ever invented.
		if ( ! $is_apex ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-cname',
					'Point the hostname at the SaaS target',
					array( new DnsRecordSpec( 'CNAME', $host, $cname_target ) ),
					false,
					'cloudflare-saas'
				),
			);
		} elseif ( ApexRouting::APEX_PROXY === $apex->routing ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-apex-proxy',
					'Point the apex at the assigned addresses',
					array_map(
						static fn( string $ip ): DnsRecordSpec => new DnsRecordSpec(
							false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'A' : 'AAAA',
							$host,
							$ip
						),
						$apex->targets
					),
					true,
					'cloudflare-saas'
				),
			);
		} elseif ( in_array( $apex->routing, array( ApexRouting::CNAME_FLATTENING, ApexRouting::ALIAS_OR_ANAME ), true ) ) {
			$dns['routing'] = array(
				new DnsRequirementSet(
					'routing',
					'routing-apex-cname',
					'Point the apex at the SaaS target (flattened)',
					array( new DnsRecordSpec( 'CNAME', $host, $cname_target ) ),
					true,
					'cloudflare-saas'
				),
			);
		} else {
			$blockers[] = new DnsBlocker(
				'apex_routing_unsupported',
				'This apex domain has no supported routing mechanism: ' . $apex->reason,
				'Move the zone to a provider with CNAME flattening, ALIAS, or ANAME, or configure attested Apex Proxying or BYOIP targets.',
				'cloudflare-saas'
			);
		}

		return new ValidationPlan( $dns, $http, $manual, $pending, $blockers );
	}
}
