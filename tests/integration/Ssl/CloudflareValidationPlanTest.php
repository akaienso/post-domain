<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Ssl\ApexCapability;
use PostDomain\Ssl\ApexRouting;
use PostDomain\Ssl\CloudflareValidationPlan;
use PostDomain\Ssl\DnsRecordSpec;
use PostDomain\Ssl\DnsRequirementSet;
use PostDomain\Ssl\ValidationPlan;
use WP_UnitTestCase;

final class CloudflareValidationPlanTest extends WP_UnitTestCase {

	private const HOST = 'post-domain-test.zirka.blue';

	private const APEX = 'zirka.blue';

	/** @param array<string, mixed> $payload */
	private function plan(
		array $payload,
		bool $is_apex = false,
		?ApexCapability $apex = null,
		string $host = self::HOST
	): ValidationPlan {
		return CloudflareValidationPlan::build(
			$payload,
			'saas.example.net',
			$apex ?? new ApexCapability( ApexRouting::CNAME_FLATTENING, 'flattening', array(), null, false ),
			$is_apex,
			'_post-domain-challenge.' . $host,
			'post-domain-verify=abc',
			$host
		);
	}

	/** @return array<string, mixed> */
	private function live_payload(): array {
		return array(
			'id'       => 'cf-resource-id',
			'hostname' => self::HOST,
			'status'   => 'pending',
			'ssl'      => array( 'status' => 'pending_validation' ),
		);
	}

	/** @return string[] */
	private function routing_names( ValidationPlan $plan ): array {
		$names = array();

		foreach ( $plan->dns['routing'] ?? array() as $set ) {
			foreach ( $set->records as $record ) {
				$names[] = $record->name;
			}
		}

		return $names;
	}

	public function test_subdomain_routing_names_the_mapped_host(): void {
		$names = $this->routing_names( $this->plan( $this->live_payload() ) );

		$this->assertSame( array( self::HOST ), $names );
	}

	public function test_apex_proxy_routing_names_the_mapped_host(): void {
		$plan = $this->plan(
			$this->live_payload(),
			true,
			new ApexCapability(
				ApexRouting::APEX_PROXY,
				'attested',
				array( '198.51.100.7', '2606:4700::1' ),
				'static_ip_prefix',
				true
			),
			self::APEX
		);

		$this->assertSame( array( self::APEX, self::APEX ), $this->routing_names( $plan ) );

		$types = array();

		foreach ( $plan->dns['routing'] as $set ) {
			foreach ( $set->records as $record ) {
				$types[] = $record->type;
			}
		}

		$this->assertSame( array( 'A', 'AAAA' ), $types );
	}

	public function test_apex_cname_flattening_routing_names_the_mapped_host(): void {
		$plan = $this->plan(
			$this->live_payload(),
			true,
			new ApexCapability( ApexRouting::CNAME_FLATTENING, 'flattening', array(), null, false ),
			self::APEX
		);

		$this->assertSame( array( self::APEX ), $this->routing_names( $plan ) );
	}

	public function test_apex_alias_routing_names_the_mapped_host(): void {
		$plan = $this->plan(
			$this->live_payload(),
			true,
			new ApexCapability( ApexRouting::ALIAS_OR_ANAME, 'alias', array(), null, false ),
			self::APEX
		);

		$this->assertSame( array( self::APEX ), $this->routing_names( $plan ) );
	}

	public function test_no_routing_record_carries_a_placeholder_name(): void {
		foreach ( $this->routing_names( $this->plan( $this->live_payload() ) ) as $name ) {
			$this->assertStringNotContainsString( ' ', $name );
		}
	}

	public function test_an_absent_provider_resource_has_nothing_pending(): void {
		$plan = $this->plan( array() );

		$this->assertSame( array(), $plan->pending );
		$this->assertArrayNotHasKey( 'provider_ownership', $plan->dns );
		$this->assertArrayNotHasKey( 'ssl_validation', $plan->dns );
	}

	public function test_an_absent_provider_resource_still_states_ownership_and_routing(): void {
		$plan = $this->plan( array() );

		$this->assertArrayHasKey( 'ownership', $plan->dns );
		$this->assertSame( array( self::HOST ), $this->routing_names( $plan ) );
	}

	public function test_a_live_resource_without_records_is_still_pending(): void {
		$plan = $this->plan( $this->live_payload() );

		$purposes = array_map(
			static fn( object $pending ): string => (string) $pending->purpose,
			$plan->pending
		);

		$this->assertSame( array( 'provider_ownership', 'ssl_validation' ), $purposes );
	}

	public function test_apex_capability_rules_are_unchanged(): void {
		$plan = $this->plan(
			$this->live_payload(),
			true,
			ApexCapability::validated(
				new ApexCapability( ApexRouting::APEX_PROXY, 'unattested', array( '198.51.100.7' ), null, false )
			),
			self::APEX
		);

		$this->assertArrayNotHasKey( 'routing', $plan->dns );
		$this->assertNotSame( array(), $plan->blockers );
	}

	public function test_all_semantics_survive_for_a_multi_record_set(): void {
		$plan = $this->plan(
			$this->live_payload(),
			true,
			new ApexCapability(
				ApexRouting::APEX_PROXY,
				'attested',
				array( '198.51.100.7', '198.51.100.8' ),
				'byoip',
				true
			),
			self::APEX
		);

		$this->assertCount( 1, $plan->dns['routing'] );
		$this->assertContainsOnlyInstancesOf( DnsRequirementSet::class, $plan->dns['routing'] );
		$this->assertContainsOnlyInstancesOf( DnsRecordSpec::class, $plan->dns['routing'][0]->records );
		$this->assertCount( 2, $plan->dns['routing'][0]->records );
		$this->assertFalse( $plan->alternatives_for( 'routing' ) );
	}
}
