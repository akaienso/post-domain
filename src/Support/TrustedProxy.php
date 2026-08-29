<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Forwarded headers are honoured only from an allowlisted REMOTE_ADDR. There is
 * no filter that turns them on without an IP allowlist: that would be host
 * header injection with extra steps.
 */
final class TrustedProxy {

	/** @var string[] */
	private array $cidrs;

	/**
	 * @param string[] $cidrs
	 */
	public function __construct( array $cidrs ) {
		$this->cidrs = array();

		foreach ( $cidrs as $cidr ) {
			if ( is_string( $cidr ) && $this->is_valid_cidr( $cidr ) ) {
				$this->cidrs[] = $cidr;
			}
		}
	}

	/** @return string[] */
	public function cidrs(): array {
		return $this->cidrs;
	}

	/**
	 * @param array<string, mixed> $server
	 */
	public function served_authority( array $server ): string {
		$direct = isset( $server['HTTP_HOST'] ) ? (string) $server['HTTP_HOST'] : '';

		if ( array() === $this->cidrs ) {
			return $direct;
		}

		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';

		if ( ! $this->is_trusted( $remote ) ) {
			return $direct;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_HOST'] )
			? (string) $server['HTTP_X_FORWARDED_HOST']
			: '';

		if ( '' === $forwarded ) {
			return $direct;
		}

		$first = explode( ',', $forwarded )[0];

		return trim( $first );
	}

	private function is_valid_cidr( string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false !== filter_var( $cidr, FILTER_VALIDATE_IP );
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );

		return false !== filter_var( $subnet, FILTER_VALIDATE_IP )
			&& 1 === preg_match( '/^[0-9]{1,3}$/', $bits );
	}

	private function is_trusted( string $address ): bool {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( $this->cidrs as $cidr ) {
			if ( $this->in_range( $address, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	private function in_range( string $address, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $address === $cidr;
		}

		[ $subnet, $bits ] = explode( '/', $cidr, 2 );

		$address_bin = inet_pton( $address );
		$subnet_bin  = inet_pton( $subnet );

		if ( false === $address_bin || false === $subnet_bin
			|| strlen( $address_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( substr( $address_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( 0xFF << ( 8 - $rest ) & 0xFF );

		return ( $address_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}
}
