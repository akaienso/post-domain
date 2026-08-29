<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\DomainDetail;
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
