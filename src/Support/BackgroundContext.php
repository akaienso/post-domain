<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Mapping\AliasResolver;
use PostDomain\Plugin;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\HostContext;
use PostDomain\Routing\HostKind;
use PostDomain\Routing\ServingEligibility;

final class BackgroundContext {

	public static function run( int $mapping_id, callable $callback ): mixed {
		$plugin  = Plugin::instance();
		$mapping = $plugin->repository()->by_id( $mapping_id );

		if ( null === $mapping ) {
			return $callback();
		}

		$host_context = new HostContext(
			$mapping->host,
			null,
			$mapping->host,
			HostKind::MAPPED,
			$mapping,
			EndpointClass::CLI,
			true,
			'GET'
		);

		$aliases     = new AliasResolver( $plugin->repository() );
		$eligibility = ServingEligibility::decide( $host_context, $aliases );
		$serving     = null === $eligibility ? null : ContentPolicy::freeze( $eligibility, $aliases );

		if ( null === $serving ) {
			return $callback();
		}

		return $plugin->context()->with( $serving, $callback );
	}

	/**
	 * @param string[] $argv
	 */
	public static function from_cli_flag( array $argv ): ?string {
		foreach ( $argv as $argument ) {
			if ( str_starts_with( $argument, '--pd-host=' ) ) {
				return substr( $argument, strlen( '--pd-host=' ) );
			}
		}

		return null;
	}
}
