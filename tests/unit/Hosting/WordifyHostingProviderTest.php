<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting;

use PHPUnit\Framework\TestCase;
use PostDomain\Hosting\HostingEnvironment;
use PostDomain\Hosting\HostingRegistrationState;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\WordifyDomainList;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifyHostingProvider;
use PostDomain\Hosting\WordifySite;
use PostDomain\Hosting\WordifySiteList;
use PostDomain\Tests\Unit\Hosting\Fixtures\FakeWordifyClient;

/*
 */
final class WordifyHostingProviderTest extends TestCase {

	private const BOUND = '01HQ0000000000000000000001';
	private const OTHER = '01HQ0000000000000000000002';
	private const HOST  = 'mapped.test';

	private function environment(): HostingEnvironment {
		return new HostingEnvironment( 'wordify', 'team-1', self::BOUND );
	}

	private function provider( FakeWordifyClient $client ): WordifyHostingProvider {
		return new WordifyHostingProvider( $client, $this->environment() );
	}

	private function context( string $host = self::HOST ): HostingResourceContext {
		return new HostingResourceContext( 7, $host, 'install-a', null, null );
	}

	private function attached_list( string $host = self::HOST ): WordifyDomainList {
		return new WordifyDomainList( array( FakeWordifyClient::domain( $host, false, 'dom-7' ) ) );
	}

	public function test_a_clean_attach_registers_and_writes_exactly_once(): void {
		$client = new FakeWordifyClient();

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::REGISTERED, $outcome->state );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
		$this->assertSame( 'wordify:team-1:' . self::BOUND, $outcome->environment_id );
	}

	public function test_the_provider_never_asks_the_client_to_promote_a_hostname_to_primary(): void {
		// The client hard-codes make_primary = false, and the provider has no way
		// to ask for anything else: attach_domain takes a site and a host only.
		$parameters = ( new \ReflectionMethod( FakeWordifyClient::class, 'attach_domain' ) )->getParameters();

		$this->assertSame( array( 'site_id', 'host' ), array_map( static fn ( $p ): string => $p->getName(), $parameters ) );

		$source = (string) file_get_contents( __DIR__ . '/../../../src/Hosting/WordifyApiClient.php' );

		$this->assertStringContainsString( "'make_primary' => false", $source );
		$this->assertStringNotContainsString( "'make_primary' => true", $source );
	}

	public function test_a_duplicate_is_idempotent_only_after_a_read_shows_it_on_the_bound_site(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::refused( 'attach_domain', 409 ) )
			->will_read_domains( $this->attached_list() );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::ALREADY_MINE, $outcome->state );
		$this->assertSame( 'dom-7', $outcome->reference );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
		$this->assertSame( 1, $client->count_of( 'domains' ) );
	}

	public function test_a_duplicate_whose_confirming_read_fails_is_ambiguous_not_already_mine(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::refused( 'attach_domain', 409 ) )
			->will_read_domains( WordifyFailure::transport( 'domains' ) )
			->will_list_sites( WordifyFailure::transport( 'sites' ) );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::AMBIGUOUS, $outcome->state );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
	}

	public function test_a_hostname_on_another_site_is_refused_and_never_adopted(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::refused( 'attach_domain', 409 ) )
			->will_read_domains( new WordifyDomainList( array() ) )
			->will_list_sites( new WordifySiteList( array( new WordifySite( self::OTHER, 'active' ) ) ) );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::FOREIGN, $outcome->state );
		$this->assertNull( $outcome->reference );
		$this->assertNull( $outcome->environment_id );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
	}

	public function test_a_timeout_whose_read_shows_the_hostname_is_recovered_without_a_second_write(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::transport( 'attach_domain' ) )
			->will_read_domains( $this->attached_list() );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::ALREADY_MINE, $outcome->state );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
	}

	public function test_a_timeout_whose_read_shows_nothing_is_ambiguous_and_is_not_rewritten(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::transport( 'attach_domain' ) )
			->will_read_domains( new WordifyDomainList( array() ) )
			->will_list_sites( new WordifySiteList( array() ) );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::AMBIGUOUS, $outcome->state );
		$this->assertSame( 1, $client->count_of( 'attach_domain' ) );
	}

	public function test_a_definite_refusal_with_a_clean_read_is_refused_not_ambiguous(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::refused( 'attach_domain', 422 ) )
			->will_read_domains( new WordifyDomainList( array() ) )
			->will_list_sites( new WordifySiteList( array() ) );

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::REFUSED, $outcome->state );
	}

	public function test_a_failed_read_identifies_as_unknown_never_absent(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( WordifyFailure::transport( 'domains' ) );

		$identity = $this->provider( $client )->identify( $this->context() );

		$this->assertFalse( $identity->read_complete );
		$this->assertFalse( $identity->attached );
		$this->assertNotNull( $identity->reason );
	}

	public function test_a_complete_read_without_the_hostname_is_absent(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( new WordifyDomainList( array() ) );

		$identity = $this->provider( $client )->identify( $this->context() );

		$this->assertTrue( $identity->read_complete );
		$this->assertFalse( $identity->attached );
	}

	public function test_a_complete_read_with_the_hostname_reports_the_bound_site(): void {
		$client = ( new FakeWordifyClient() )->will_read_domains( $this->attached_list() );

		$identity = $this->provider( $client )->identify( $this->context() );

		$this->assertTrue( $identity->attached );
		$this->assertSame( self::BOUND, $identity->attached_site_id );
		$this->assertSame( 'dom-7', $identity->reference );
	}

	public function test_detach_is_unsupported_and_is_never_claimed_as_supported(): void {
		$provider = $this->provider( new FakeWordifyClient() );

		$this->assertFalse( $provider->supports_detach() );
		$this->assertSame( HostingRegistrationState::UNSUPPORTED, $provider->detach( $this->context() )->state );
	}

	public function test_registering_and_identifying_never_recheck_dns(): void {
		$client = ( new FakeWordifyClient() )
			->will_attach( WordifyFailure::refused( 'attach_domain', 409 ) )
			->will_read_domains( WordifyFailure::transport( 'domains' ) )
			->will_list_sites( WordifyFailure::transport( 'sites' ) );

		$provider = $this->provider( $client );
		$provider->register( $this->context() );
		$provider->identify( $this->context() );

		$this->assertSame( 0, $client->count_of( 'recheck' ) );
	}

	public function test_an_unready_provider_refuses_rather_than_writing(): void {
		$client        = new FakeWordifyClient();
		$client->ready = false;

		$outcome = $this->provider( $client )->register( $this->context() );

		$this->assertSame( HostingRegistrationState::REFUSED, $outcome->state );
		$this->assertSame( 0, $client->count_of( 'attach_domain' ) );
	}
}
