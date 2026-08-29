<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Support\PublicSuffix;

final class ApexCapabilityTest extends TestCase {

	public function test_apex_detection_uses_the_public_suffix_list(): void {
		$this->assertTrue( PublicSuffix::is_apex( 'example.com' ) );
		$this->assertFalse( PublicSuffix::is_apex( 'shop.example.com' ) );
	}

	public function test_a_multi_label_suffix_is_handled_correctly(): void {
		$this->assertTrue(
			PublicSuffix::is_apex( 'example.co.uk' ),
			'a label count would get this wrong'
		);
		$this->assertFalse( PublicSuffix::is_apex( 'shop.example.co.uk' ) );
	}

	public function test_cname_flattening_needs_no_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::CNAME_FLATTENING, 'zone is on Cloudflare', array(), null, false )
		);

		$this->assertSame( ApexRouting::CNAME_FLATTENING, $capability->routing );
	}

	public function test_apex_proxy_requires_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array(), 'byoip', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_valid_ip_targets(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( 'not-an-ip' ), 'byoip', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_a_declared_provenance(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), null, true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_apex_proxy_requires_an_operator_attestation(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'static_ip_prefix', false )
		);

		$this->assertSame(
			ApexRouting::UNSUPPORTED,
			$capability->routing,
			'entitlement is never inferred from address strings alone'
		);
	}

	public function test_a_fully_attested_apex_proxy_capability_is_accepted(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'static_ip_prefix', true )
		);

		$this->assertSame( ApexRouting::APEX_PROXY, $capability->routing );
		$this->assertSame( array( '203.0.113.5' ), $capability->targets );
	}

	public function test_an_unknown_provenance_is_rejected(): void {
		$capability = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'my-origin-server', true )
		);

		$this->assertSame( ApexRouting::UNSUPPORTED, $capability->routing );
	}

	public function test_a_non_capability_value_becomes_unsupported(): void {
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( 'yes please' )->routing );
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( true )->routing );
		$this->assertSame( ApexRouting::UNSUPPORTED, ApexCapability::validated( null )->routing );
	}
}
