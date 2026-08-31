<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\HostingRegistrationState;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\ManualHostingProvider;

final class ManualHostingProviderTest extends TestCase {

	private function context(): HostingResourceContext {
		return new HostingResourceContext( 3, 'manual.test', 'install-a', null, null );
	}

	public function test_it_is_always_ready_and_bound_to_nothing(): void {
		$provider = new ManualHostingProvider();

		$this->assertSame( 'manual', $provider->id() );
		$this->assertTrue( $provider->is_ready() );
		$this->assertNull( $provider->environment() );
	}

	public function test_it_reports_absence_completely_rather_than_unknown(): void {
		$identity = ( new ManualHostingProvider() )->identify( $this->context() );

		$this->assertTrue( $identity->read_complete );
		$this->assertFalse( $identity->attached );
	}

	public function test_registration_and_detachment_are_unsupported(): void {
		$provider = new ManualHostingProvider();

		$this->assertSame( HostingRegistrationState::UNSUPPORTED, $provider->register( $this->context() )->state );
		$this->assertFalse( $provider->supports_detach() );
		$this->assertSame( HostingRegistrationState::UNSUPPORTED, $provider->detach( $this->context() )->state );
	}

	public function test_an_unsupported_outcome_still_counts_as_success_for_the_manual_workflow(): void {
		$this->assertTrue( ( new ManualHostingProvider() )->register( $this->context() )->succeeded() );
	}
}
