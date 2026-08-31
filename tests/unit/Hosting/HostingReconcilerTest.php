<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\HostingEnvironment;
use PostDomain\Hosting\HostingReconciler;
use PostDomain\Hosting\HostingRegistrationState;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\WordifyDomainList;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifyHostingProvider;
use PostDomain\Tests\Unit\Hosting\Fixtures\FakeWordifyClient;

final class HostingReconcilerTest extends TestCase {

	private const BOUND = '01HQ0000000000000000000001';
	private const HOST  = 'ambiguous.test';

	private function reconciler( FakeWordifyClient $client ): HostingReconciler {
		return new HostingReconciler(
			new WordifyHostingProvider( $client, new HostingEnvironment( 'wordify', 'team-1', self::BOUND ) )
		);
	}

	private function context(): HostingResourceContext {
		return new HostingResourceContext( 9, self::HOST, 'install-a', null, null );
	}

	public function test_a_read_showing_the_hostname_settles_the_registration(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains(
			new WordifyDomainList( array( FakeWordifyClient::domain( self::HOST, false, 'dom-9' ) ) )
		);

		$outcome = $this->reconciler( $client )->resolve( $this->context() );

		$this->assertSame( HostingRegistrationState::ALREADY_MINE, $outcome->state );
		$this->assertSame( 'dom-9', $outcome->reference );
	}

	public function test_a_read_that_fails_leaves_it_ambiguous_and_writes_nothing(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( WordifyFailure::transport( 'domains' ) );

		$outcome = $this->reconciler( $client )->resolve( $this->context() );

		$this->assertSame( HostingRegistrationState::AMBIGUOUS, $outcome->state );
		$this->assertSame( 0, $client->count_of( 'attach_domain' ) );
	}

	public function test_a_read_showing_nothing_settles_it_as_never_registered(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( new WordifyDomainList( array() ) );

		$this->assertSame(
			HostingRegistrationState::REFUSED,
			$this->reconciler( $client )->resolve( $this->context() )->state
		);
	}

	public function test_it_is_bounded_and_gives_up_rather_than_looping(): void {
		$client  = ( new FakeWordifyClient() )->will_read_domains( WordifyFailure::transport( 'domains' ) );
		$outcome = $this->reconciler( $client )->resolve( $this->context(), HostingReconciler::MAX_ATTEMPTS );

		$this->assertSame( HostingRegistrationState::REFUSED, $outcome->state );
		$this->assertSame( 0, $client->count_of( 'domains' ), 'Past the bound it stops reading too.' );
	}

	public function test_it_never_rechecks_dns_and_never_writes(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( new WordifyDomainList( array() ) );

		$this->reconciler( $client )->resolve( $this->context() );

		$this->assertSame( 0, $client->count_of( 'recheck' ) );
		$this->assertSame( 0, $client->count_of( 'attach_domain' ) );
	}
}
