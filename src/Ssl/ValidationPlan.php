<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ValidationPlan {

	/**
	 * @param array<string, array<int, object>> $dns
	 * @param array<int, object>                $http
	 * @param array<int, object>                $manual
	 * @param array<int, object>                $pending
	 * @param array<int, object>                $blockers
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
		$http = count(
			array_filter( $this->http, static fn( object $h ): bool => ( $h->purpose ?? '' ) === $purpose )
		);

		return ( $dns + $http ) > 1;
	}
}
