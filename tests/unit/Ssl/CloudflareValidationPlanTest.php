<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Ssl\CloudflareValidationPlan;

final class CloudflareValidationPlanTest extends TestCase {

	private function build( array $payload, bool $is_apex = false, ?ApexCapability $apex = null ) {
		return CloudflareValidationPlan::build(
			$payload,
			'saas.example.net',
			$apex ?? new ApexCapability( ApexRouting::CNAME_FLATTENING, 'zone on Cloudflare', array(), null, false ),
			$is_apex,
			'_post-domain-challenge.mapped.test',
			'post-domain-verify=abc'
		);
	}

	public function test_core_contributes_exactly_one_permanent_ownership_record(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertCount( 1, $plan->dns['ownership'] );
		$this->assertSame( 'core', $plan->dns['ownership'][0]->source );
		$this->assertFalse( $plan->dns['ownership'][0]->removable_once_active );
	}

	public function test_a_provider_ownership_txt_becomes_a_removable_requirement(): void {
		$plan = $this->build(
			array(
				'status'                 => 'pending',
				'ownership_verification' => array(
					'type'  => 'txt',
					'name'  => '_cf-custom-hostname.mapped.test',
					'value' => '0e2d5a7f',
				),
			)
		);

		$set = $plan->dns['provider_ownership'][0];

		$this->assertSame( 'cf-hostname-txt', $set->id );
		$this->assertTrue( $set->removable_once_active );
		$this->assertSame( '_cf-custom-hostname.mapped.test', $set->records[0]->name );
	}

	public function test_a_provider_ownership_http_token_is_not_a_dns_record(): void {
		$plan = $this->build(
			array(
				'status'                      => 'pending',
				'ownership_verification_http' => array(
					'http_url'  => 'http://mapped.test/.well-known/cf-custom-hostname-challenge/abc',
					'http_body' => 'token-value',
				),
			)
		);

		$this->assertArrayNotHasKey( 'provider_ownership', $plan->dns );
		$this->assertCount( 1, $plan->http );
		$this->assertSame( 'provider_ownership', $plan->http[0]->purpose );
	}

	public function test_both_ownership_forms_present_render_as_alternatives(): void {
		$plan = $this->build(
			array(
				'status'                      => 'pending',
				'ownership_verification'      => array(
					'type'  => 'txt',
					'name'  => '_cf.mapped.test',
					'value' => 'v',
				),
				'ownership_verification_http' => array(
					'http_url'  => 'http://x/y',
					'http_body' => 'b',
				),
			)
		);

		$this->assertCount( 1, $plan->dns['provider_ownership'] );
		$this->assertCount( 1, $plan->http );
		$this->assertTrue( $plan->alternatives_for( 'provider_ownership' ) );
	}

	public function test_neither_ownership_form_while_pending_is_a_wait_not_a_blocker(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertSame( array(), $plan->blockers );
		$this->assertNotEmpty(
			array_filter( $plan->pending, static fn( $p ): bool => 'provider_ownership' === $p->purpose )
		);
	}

	public function test_an_active_hostname_suppresses_completed_ownership_instructions(): void {
		$plan = $this->build(
			array(
				'status'                 => 'active',
				'ownership_verification' => array(
					'type'  => 'txt',
					'name'  => '_cf.mapped.test',
					'value' => 'v',
				),
			)
		);

		$this->assertArrayNotHasKey( 'provider_ownership', $plan->dns );
	}

	public function test_malformed_ownership_data_becomes_a_blocker(): void {
		$plan = $this->build(
			array(
				'status'                 => 'pending',
				'ownership_verification' => array(
					'type' => 'txt',
					'name' => '',
				),
			)
		);

		$this->assertNotEmpty( $plan->blockers );
		$this->assertSame( 'provider_record_malformed', $plan->blockers[0]->code );
	}

	public function test_a_dcv_txt_record_becomes_an_ssl_validation_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array(
							'txt_name'  => '_acme-challenge.mapped.test',
							'txt_value' => 'abc',
						),
					),
				),
			)
		);

		$this->assertSame( 'cf-dcv-txt', $plan->dns['ssl_validation'][0]->id );
	}

	public function test_a_dcv_http_token_is_an_http_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array(
							'http_url'  => 'http://mapped.test/.well-known/pki-validation/x.txt',
							'http_body' => 'y',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $plan->http );
		$this->assertSame( 'ssl_validation', $plan->http[0]->purpose );
	}

	public function test_email_dcv_becomes_a_manual_requirement(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(
						array( 'emails' => array( 'admin@mapped.test', 'webmaster@mapped.test' ) ),
					),
				),
			)
		);

		$this->assertCount( 1, $plan->manual );
		$this->assertContains( 'admin@mapped.test', $plan->manual[0]->contacts );
	}

	public function test_empty_validation_records_shortly_after_create_is_pending(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array(),
				),
			)
		);

		$this->assertNotEmpty(
			array_filter( $plan->pending, static fn( $p ): bool => 'ssl_validation' === $p->purpose )
		);
		$this->assertSame( array(), $plan->blockers );
	}

	public function test_an_unrecognised_validation_record_becomes_a_blocker(): void {
		$plan = $this->build(
			array(
				'status' => 'active',
				'ssl'    => array(
					'status'             => 'pending_validation',
					'validation_records' => array( array( 'mystery_field' => 'x' ) ),
				),
			)
		);

		$this->assertSame( 'provider_record_malformed', $plan->blockers[0]->code );
	}

	public function test_a_non_apex_host_gets_a_cname_routing_set(): void {
		$plan = $this->build( array( 'status' => 'pending' ) );

		$this->assertSame( 'CNAME', $plan->dns['routing'][0]->records[0]->type );
		$this->assertSame( 'saas.example.net', $plan->dns['routing'][0]->records[0]->value );
		$this->assertFalse( $plan->dns['routing'][0]->apex_compatible );
	}

	public function test_an_apex_host_with_flattening_gets_a_cname_set(): void {
		$plan = $this->build( array( 'status' => 'pending' ), true );

		$this->assertSame( 'CNAME', $plan->dns['routing'][0]->records[0]->type );
		$this->assertTrue( $plan->dns['routing'][0]->apex_compatible );
	}

	public function test_an_apex_host_with_attested_proxying_gets_a_records(): void {
		$apex = ApexCapability::validated(
			new ApexCapability( ApexRouting::APEX_PROXY, 'configured', array( '203.0.113.5' ), 'byoip', true )
		);

		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		$this->assertSame( 'A', $plan->dns['routing'][0]->records[0]->type );
		$this->assertSame( '203.0.113.5', $plan->dns['routing'][0]->records[0]->value );
	}

	public function test_an_apex_host_without_capability_gets_a_blocker_and_no_routing(): void {
		$apex = ApexCapability::unsupported( 'no apex-capable target configured' );
		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		$this->assertArrayNotHasKey( 'routing', $plan->dns );
		$this->assertNotEmpty( $plan->blockers );
	}

	public function test_no_record_type_is_ever_the_literal_unsupported(): void {
		$apex = ApexCapability::unsupported( 'none' );
		$plan = $this->build( array( 'status' => 'pending' ), true, $apex );

		foreach ( $plan->dns as $sets ) {
			foreach ( $sets as $set ) {
				foreach ( $set->records as $record ) {
					$this->assertNotSame( 'unsupported', $record->type );
				}
			}
		}
	}
}
