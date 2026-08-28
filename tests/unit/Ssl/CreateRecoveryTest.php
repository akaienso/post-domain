<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Ssl;

use PHPUnit\Framework\TestCase;
use PostDomain\Ssl\CreateRecovery;
use PostDomain\Ssl\IdentityResult;
use PostDomain\Ssl\IdentityVerdict;
use PostDomain\Ssl\MarkerSupport;
use PostDomain\Ssl\ProviderMarker;
use PostDomain\Ssl\SslResourceContext;

final class CreateRecoveryTest extends TestCase {

	private function context( ?string $ref = null ): SslResourceContext {
		return new SslResourceContext(
			12,
			'mapped.test',
			'install-a',
			'test-driver',
			null === $ref ? null : 'test-driver:default',
			$ref,
			null,
			null,
			'_post-domain-challenge.mapped.test',
			'post-domain-verify=abc',
			'abc',
			3
		);
	}

	private function identity(
		IdentityVerdict $verdict,
		?string $observed_ref,
		?ProviderMarker $marker,
		MarkerSupport $support,
		bool $complete = true,
		bool $transient = false
	): IdentityResult {
		return new IdentityResult(
			$verdict,
			null,
			$observed_ref,
			'mapped.test',
			$marker,
			$support,
			$complete,
			$transient
		);
	}

	public function test_case_c_conclusive_absence_is_retryable(): void {
		$this->assertSame(
			CreateRecovery::RETRY,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::ABSENT, null, null, MarkerSupport::SUPPORTED ),
				$this->context()
			)
		);
	}

	public function test_case_d_a_marker_naming_this_install_and_mapping_binds(): void {
		$this->assertSame(
			CreateRecovery::BIND,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_case_d_does_not_apply_to_another_mapping(): void {
		$this->assertSame(
			CreateRecovery::UNOWNED,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 99, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_case_e_markers_unavailable_requires_explicit_adoption(): void {
		$this->assertSame(
			CreateRecovery::ADOPT_REQUIRED,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::MISMATCH, 'ref-9', null, MarkerSupport::UNAVAILABLE ),
				$this->context()
			),
			'the plugin refuses to guess which unbound resource is its own'
		);
	}

	public function test_case_e_an_absent_marker_also_requires_adoption(): void {
		$this->assertSame(
			CreateRecovery::ADOPT_REQUIRED,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::MISMATCH, 'ref-9', null, MarkerSupport::SUPPORTED ),
				$this->context()
			)
		);
	}

	public function test_case_f_a_foreign_marker_is_unowned(): void {
		$this->assertSame(
			CreateRecovery::UNOWNED,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::MISMATCH,
					'ref-9',
					new ProviderMarker( 'other-install', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context()
			)
		);
	}

	public function test_an_incomplete_or_transient_read_waits(): void {
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, false ),
				$this->context()
			)
		);
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity( IdentityVerdict::UNKNOWN, null, null, MarkerSupport::UNKNOWN, true, true ),
				$this->context()
			)
		);
	}

	public function test_recovery_never_applies_once_a_reference_is_bound(): void {
		$this->assertSame(
			CreateRecovery::WAIT,
			CreateRecovery::decide(
				$this->identity(
					IdentityVerdict::RECOVERABLE_CREATE,
					'ref-9',
					new ProviderMarker( 'install-a', 12, array() ),
					MarkerSupport::SUPPORTED
				),
				$this->context( 'ref-1' )
			),
			'a bound reference uses the strict MATCH rule, not recovery'
		);
	}
}
