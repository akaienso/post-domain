<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPlan {

	/**
	 * @param array<string, DnsRequirementSet[]> $dns
	 * @param HttpRequirementSet[]               $http
	 * @param ManualRequirement[]                $manual
	 * @param ValidationPending[]                $pending
	 * @param DnsBlocker[]                       $blockers
	 */
	public function __construct(
		public readonly array $dns,
		public readonly array $http,
		public readonly array $manual,
		public readonly array $pending,
		public readonly array $blockers
	) {}

	/** True when a purpose offers more than one genuinely sufficient route. */
	public function alternatives_for( string $purpose ): bool {
		$dns  = count( $this->dns[ $purpose ] ?? array() );
		$http = count( array_filter( $this->http, static fn( HttpRequirementSet $h ): bool => $h->purpose === $purpose ) );

		return ( $dns + $http ) > 1;
	}
}
