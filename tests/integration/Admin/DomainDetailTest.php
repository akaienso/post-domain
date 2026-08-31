<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\DomainDetail;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DnsBlocker;
use PostDomain\Ssl\DnsRecordSpec;
use PostDomain\Ssl\DnsRequirementSet;
use PostDomain\Ssl\HttpRequirementSet;
use PostDomain\Ssl\ManualRequirement;
use PostDomain\Ssl\ValidationPending;
use PostDomain\Ssl\ValidationPlan;
use WP_UnitTestCase;

final class DomainDetailTest extends WP_UnitTestCase {

	private function plan(): ValidationPlan {
		return new ValidationPlan(
			array(
				'ownership'          => array(
					new DnsRequirementSet(
						'ownership',
						'core-ownership',
						'Ownership TXT',
						array( new DnsRecordSpec( 'TXT', '_post-domain-challenge.mapped.test', 'post-domain-verify=abc' ) ),
						true,
						'core',
						false
					),
				),
				'provider_ownership' => array(
					new DnsRequirementSet(
						'provider_ownership',
						'cf-hostname-txt',
						'Cloudflare hostname ownership',
						array( new DnsRecordSpec( 'TXT', '_cf-custom-hostname.mapped.test', 'uuid' ) ),
						true,
						'cloudflare-saas',
						true
					),
				),
				'routing'            => array(
					new DnsRequirementSet(
						'routing',
						'routing-cname',
						'Point the hostname at the SaaS target',
						array( new DnsRecordSpec( 'CNAME', 'mapped.test', 'saas.example.net' ) ),
						false,
						'cloudflare-saas'
					),
				),
			),
			array(
				new HttpRequirementSet(
					'ssl_validation',
					'cf-dcv-http',
					'Certificate validation HTTP token',
					'http://mapped.test/.well-known/pki-validation/x.txt',
					'token',
					'cloudflare-saas'
				),
			),
			array(
				new ManualRequirement(
					'ssl_validation',
					'cf-dcv-email',
					'Certificate validation email',
					'A person must open the approval email.',
					array( 'admin@mapped.test' ),
					'cloudflare-saas'
				),
			),
			array( new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' ) ),
			array(
				new DnsBlocker(
					'apex_routing_unsupported',
					'This apex has no supported routing mechanism.',
					'Configure CNAME flattening or attested Apex Proxying targets.',
					'cloudflare-saas'
				),
			)
		);
	}

	/**
	 * The five SSL binding columns move together: a bound row sets all of them, an
	 * unbound row sets none.
	 */
	private function mapping( SslState $state, bool $bound, ?string $mutation_token = null ): Mapping {
		return new Mapping(
			1,
			'mapped.test',
			null,
			7,
			3,
			VerificationState::VERIFIED,
			ActivationState::ACTIVE,
			$state,
			null,
			'abc',
			'_post-domain-challenge',
			$bound ? OwnershipOrigin::CREATED : null,
			$bound ? 'install-1' : null,
			$bound ? 'cloudflare-saas' : null,
			$bound ? 'zone-1' : null,
			$bound ? 'cf-resource-id' : null,
			null,
			$mutation_token
		);
	}

	private function substring_count( string $haystack, string $needle ): int {
		return substr_count( $haystack, $needle );
	}

	public function test_the_permanent_ownership_record_is_marked_permanent(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'must never be removed', $html );
	}

	public function test_the_provider_ownership_record_is_marked_removable(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'may be removed once', $html );
	}

	public function test_the_four_purposes_are_separate_sections(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		foreach (
			array(
				'Ownership (post-domain)',
				'Hostname ownership (provider)',
				'Certificate validation (provider)',
				'Routing',
			) as $heading
		) {
			$this->assertStringContainsString( $heading, $html );
		}
	}

	public function test_an_http_token_is_not_rendered_as_a_dns_record(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Serve this at', $html );
		$this->assertStringNotContainsString( '<td>HTTP</td>', $html );
	}

	public function test_a_manual_requirement_says_it_needs_a_person(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'admin@mapped.test', $html );
		$this->assertStringContainsString( 'cannot be automated', $html );
	}

	public function test_a_pending_purpose_reads_as_a_wait_not_a_failure(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Awaiting provider', $html );
		$this->assertStringNotContainsString( 'Awaiting provider</h3><p class="pd-blocker"', $html );
	}

	public function test_a_blocker_carries_its_remedy(): void {
		$html = DomainDetail::render_plan( $this->plan() );

		$this->assertStringContainsString( 'Configure CNAME flattening', $html );
	}

	public function test_record_values_are_escaped(): void {
		$plan = new ValidationPlan(
			array(
				'ownership' => array(
					new DnsRequirementSet(
						'ownership',
						'x',
						'x',
						array( new DnsRecordSpec( 'TXT', 'name', '<script>alert(1)</script>' ) ),
						true,
						'core'
					),
				),
			),
			array(),
			array(),
			array(),
			array()
		);

		$this->assertStringNotContainsString( '<script>', DomainDetail::render_plan( $plan ) );
	}

	public function test_the_ownership_txt_renders_exactly_once_when_the_driver_also_supplied_it(): void {
		$html = DomainDetail::render_plan( $this->plan(), $this->mapping( SslState::PENDING_VALIDATION, true ) );

		$this->assertSame( 1, $this->substring_count( $html, '_post-domain-challenge.mapped.test' ) );
	}

	public function test_the_ownership_txt_renders_once_even_when_offered_twice(): void {
		$plan    = $this->plan();
		$doubled = new ValidationPlan(
			array(
				'ownership' => array(
					$plan->dns['ownership'][0],
					new DnsRequirementSet(
						'ownership',
						'aggregate-ownership',
						'Ownership TXT (aggregate)',
						array(
							new DnsRecordSpec( 'TXT', '_post-domain-challenge.mapped.test', 'post-domain-verify=abc' ),
						),
						true,
						'core',
						false
					),
				),
			),
			array(),
			array(),
			array(),
			array()
		);

		$html = DomainDetail::render_plan( $doubled, $this->mapping( SslState::PENDING_VALIDATION, true ) );

		$this->assertSame( 1, $this->substring_count( $html, '_post-domain-challenge.mapped.test' ) );
	}

	public function test_the_ownership_txt_renders_even_when_the_driver_supplied_none(): void {
		$empty = new ValidationPlan( array(), array(), array(), array(), array() );

		$html = DomainDetail::render_plan( $empty, $this->mapping( SslState::NONE, false ) );

		$this->assertStringContainsString( '_post-domain-challenge.mapped.test', $html );
		$this->assertStringContainsString( 'post-domain-verify=abc', $html );
		$this->assertStringContainsString( 'Ownership (post-domain)', $html );
	}

	public function test_each_purpose_states_its_lifetime(): void {
		$html = DomainDetail::render_plan( $this->plan(), $this->mapping( SslState::PENDING_VALIDATION, true ) );

		$this->assertStringContainsString( 'must never be removed', $html );
		$this->assertStringContainsString( 'may be removed once', $html );
		$this->assertStringContainsString( 'needed again at renewal', $html );
		$this->assertStringContainsString( 'must stay while the mapping exists', $html );
	}

	public function test_or_semantics_are_stated_only_where_the_provider_offers_alternatives(): void {
		$html = DomainDetail::render_plan( $this->plan(), $this->mapping( SslState::PENDING_VALIDATION, true ) );

		$this->assertStringNotContainsString( 'Create any one of these', $html );

		$alternatives = new ValidationPlan(
			array(
				'provider_ownership' => array(
					new DnsRequirementSet(
						'provider_ownership',
						'cf-hostname-txt',
						'Cloudflare hostname ownership',
						array( new DnsRecordSpec( 'TXT', '_cf-custom-hostname.mapped.test', 'uuid' ) ),
						true,
						'cloudflare-saas',
						true
					),
				),
			),
			array(
				new HttpRequirementSet(
					'provider_ownership',
					'cf-hostname-http',
					'Cloudflare hostname ownership HTTP token',
					'http://mapped.test/.well-known/cf-custom-hostname-challenge/x',
					'token',
					'cloudflare-saas',
					true
				),
			),
			array(),
			array(),
			array()
		);

		$this->assertStringContainsString(
			'Create any one of these',
			DomainDetail::render_plan( $alternatives, $this->mapping( SslState::PENDING_VALIDATION, true ) )
		);
	}

	public function test_all_semantics_render_every_record_in_a_set(): void {
		$all = new ValidationPlan(
			array(
				'routing' => array(
					new DnsRequirementSet(
						'routing',
						'routing-apex-proxy',
						'Point the apex at the assigned addresses',
						array(
							new DnsRecordSpec( 'A', 'mapped.test', '198.51.100.7' ),
							new DnsRecordSpec( 'AAAA', 'mapped.test', '2606:4700::1' ),
						),
						true,
						'cloudflare-saas'
					),
				),
			),
			array(),
			array(),
			array(),
			array()
		);

		$html = DomainDetail::render_plan( $all, $this->mapping( SslState::ACTIVE, true ) );

		$this->assertStringNotContainsString( 'Create any one of these', $html );
		$this->assertStringContainsString( '198.51.100.7', $html );
		$this->assertStringContainsString( '2606:4700::1', $html );
	}

	public function test_no_provider_wait_is_shown_for_a_state_with_no_resource(): void {
		foreach ( array( SslState::NONE, SslState::REVOKED ) as $state ) {
			$this->assertStringNotContainsString(
				'Awaiting provider',
				DomainDetail::render_plan( $this->plan(), $this->mapping( $state, false ) ),
				$state->value
			);
		}
	}

	public function test_no_provider_wait_is_shown_for_a_row_with_no_outstanding_work(): void {
		$html = DomainDetail::render_plan( $this->plan(), $this->mapping( SslState::FAILED, false ) );

		$this->assertStringNotContainsString( 'Awaiting provider', $html );
	}

	public function test_the_provider_wait_is_still_shown_for_a_live_resource(): void {
		$html = DomainDetail::render_plan( $this->plan(), $this->mapping( SslState::PENDING_VALIDATION, true ) );

		$this->assertStringContainsString( 'Awaiting provider', $html );
	}

	public function test_the_provider_wait_is_shown_while_a_mutation_lease_is_held(): void {
		$html = DomainDetail::render_plan(
			$this->plan(),
			$this->mapping( SslState::REQUESTED, false, 'lease-token' )
		);

		$this->assertStringContainsString( 'Awaiting provider', $html );
	}

	public function test_the_deletion_checklist_names_the_failing_precondition(): void {
		$html = DomainDetail::render_deletion_checklist(
			array(
				'environment_resolved' => true,
				'driver_registered'    => true,
				'identity_confirmed'   => false,
				'ownership_authority'  => true,
				'fresh_proof'          => true,
				'lease_acquired'       => true,
			)
		);

		$this->assertStringContainsString( 'identity_confirmed', $html );
		$this->assertStringContainsString( 'pd-check-failed', $html );
	}

	public function test_the_checklist_shows_all_six_preconditions(): void {
		$html = DomainDetail::render_deletion_checklist( array() );

		foreach (
			array(
				'environment_resolved',
				'driver_registered',
				'identity_confirmed',
				'ownership_authority',
				'fresh_proof',
				'lease_acquired',
			) as $precondition
		) {
			$this->assertStringContainsString( $precondition, $html );
		}
	}
}
