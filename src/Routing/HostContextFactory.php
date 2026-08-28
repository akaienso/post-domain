<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\TrustedProxy;

final class HostContextFactory {

	public function __construct(
		private readonly TrustedProxy $proxy,
		private readonly AuthorityParser $parser,
		private readonly InfrastructureAllowlist $allowlist,
		private readonly HostNormalizer $normalizer,
		private readonly Classifier $classifier,
		private readonly MappingRepository $repo,
		private readonly string $primary_host
	) {}

	/**
	 * @param array<string, mixed> $server
	 * @param array<string, mixed> $get
	 */
	public function build( array $server, array $get ): HostContext {
		$raw      = $this->proxy->served_authority( $server );
		$path     = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$endpoint = $this->classifier->classify( $path, $server, $get );
		$https    = ! empty( $server['HTTPS'] ) && 'off' !== $server['HTTPS'];
		$method   = strtoupper( isset( $server['REQUEST_METHOD'] ) ? (string) $server['REQUEST_METHOD'] : 'GET' );

		$authority = $this->parser->parse( $raw );

		if ( null === $authority ) {
			return new HostContext( $raw, null, null, HostKind::MALFORMED, null, $endpoint, $https, $method );
		}

		if ( $this->allowlist->allows( $authority ) ) {
			return new HostContext(
				$raw,
				$authority,
				null,
				HostKind::ALLOWED_INFRASTRUCTURE,
				null,
				$endpoint,
				$https,
				$method
			);
		}

		$ascii = $this->normalizer->normalize( $authority );

		if ( null === $ascii ) {
			return new HostContext( $raw, $authority, null, HostKind::MALFORMED, null, $endpoint, $https, $method );
		}

		if ( $ascii === $this->primary_host ) {
			return new HostContext( $raw, $authority, $ascii, HostKind::PRIMARY, null, $endpoint, $https, $method );
		}

		$mapping = $this->repo->by_host( $ascii );

		return new HostContext(
			$raw,
			$authority,
			$ascii,
			null === $mapping ? HostKind::UNKNOWN : HostKind::MAPPED,
			$mapping,
			$endpoint,
			$https,
			$method
		);
	}
}
