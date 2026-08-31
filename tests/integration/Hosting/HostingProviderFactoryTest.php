<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

use PostDomain\Contracts\HostingProvider;
use PostDomain\Hosting\HostingEnvironment;
use PostDomain\Hosting\HostingIdentityResult;
use PostDomain\Hosting\HostingProviderFactory;
use PostDomain\Hosting\HostingProviderUnavailable;
use PostDomain\Hosting\HostingResourceContext;
use PostDomain\Hosting\ManualHostingProvider;
use PostDomain\Hosting\RegistrationOutcome;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use WP_UnitTestCase;

// PSR-4 cannot resolve by class name. Loaded by hand; the file belongs to
// another track and is reported rather than edited.

/**
 * The factory's one non-negotiable behaviour: a configured provider that cannot
 * be used surfaces as a refusal, and never as the manual provider.
 */
final class HostingProviderFactoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pd_settings' );
		HostingProviderFactory::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_hosting_providers' );
		delete_option( 'pd_hosting_credentials' );
		delete_option( 'pd_settings' );
		HostingProviderFactory::reset();
		parent::tear_down();
	}

	private function select( string $id ): void {
		update_option( 'pd_settings', array( 'hosting_provider' => $id ), false );
		HostingProviderFactory::reset();
	}

	/** A provider that is registered but refuses to say it is usable. */
	private function register_unready_provider( string $id, bool $ready ): void {
		add_filter(
			'pd_hosting_providers',
			static function ( array $providers ) use ( $id, $ready ): array {
				$providers[] = new class( $id, $ready ) implements HostingProvider {
					public function __construct( private string $provider_id, private bool $ready ) {}

					public function id(): string {
						return $this->provider_id;
					}

					public function environment(): ?HostingEnvironment {
						return new HostingEnvironment( $this->provider_id, 'team-1', 'site-1' );
					}

					public function is_ready(): bool {
						return $this->ready;
					}

					public function identify( HostingResourceContext $context ): HostingIdentityResult {
						return HostingIdentityResult::unknown( 'test' );
					}

					public function register( HostingResourceContext $context ): RegistrationOutcome {
						return RegistrationOutcome::ambiguous( 'test' );
					}

					public function supports_detach(): bool {
						return false;
					}

					public function detach( HostingResourceContext $context ): RegistrationOutcome {
						return RegistrationOutcome::unsupported();
					}
				};

				return $providers;
			}
		);

		HostingProviderFactory::reset();
	}

	private function mapping( ?string $provider, ?string $environment ): Mapping {
		return new Mapping(
			1,
			'mapped-' . wp_generate_password( 8, false ) . '.test',
			null,
			5,
			1,
			VerificationState::VERIFIED,
			ActivationState::ACTIVE,
			SslState::NONE,
			null,
			'challenge-' . wp_generate_password( 12, false ),
			'label',
			hosting_provider: $provider,
			hosting_environment: $environment
		);
	}

	public function test_the_default_selection_is_the_manual_provider(): void {
		$this->assertInstanceOf( ManualHostingProvider::class, HostingProviderFactory::for_new_mapping() );
	}

	public function test_an_unconfigured_wordify_selection_refuses_rather_than_falling_back_to_manual(): void {
		$this->select( 'wordify' );

		$resolved = HostingProviderFactory::for_new_mapping();

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertNotInstanceOf( ManualHostingProvider::class, $resolved );
		$this->assertSame( 'hosting_provider_not_registered', $resolved->reason );
		$this->assertSame( 'wordify', $resolved->provider_id );
	}

	public function test_a_registered_but_unready_provider_refuses_rather_than_falling_back_to_manual(): void {
		$this->register_unready_provider( 'half-built', false );
		$this->select( 'half-built' );

		$resolved = HostingProviderFactory::for_new_mapping();

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertNotInstanceOf( ManualHostingProvider::class, $resolved );
		$this->assertSame( 'hosting_provider_not_ready', $resolved->reason );
	}

	public function test_an_unknown_selection_refuses_by_name(): void {
		$this->select( 'not-a-provider' );

		$resolved = HostingProviderFactory::for_new_mapping();

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertSame( 'not-a-provider', $resolved->provider_id );
		$this->assertNotSame( '', $resolved->detail() );
	}

	public function test_a_ready_registered_provider_is_selected(): void {
		$this->register_unready_provider( 'ready-one', true );
		$this->select( 'ready-one' );

		$resolved = HostingProviderFactory::for_new_mapping();

		$this->assertInstanceOf( HostingProvider::class, $resolved );
		$this->assertSame( 'ready-one', $resolved->id() );
	}

	public function test_a_bound_row_is_never_reinterpreted_by_a_provider_in_another_environment(): void {
		$this->register_unready_provider( 'ready-one', true );
		$this->select( 'ready-one' );

		$resolved = HostingProviderFactory::for_mapping(
			$this->mapping( 'ready-one', 'ready-one:team-9:site-9' )
		);

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertSame( 'hosting_environment_changed', $resolved->reason );
		$this->assertSame( 'ready-one:team-9:site-9', $resolved->expected_environment );
		$this->assertSame( 'ready-one:team-1:site-1', $resolved->configured_environment );
	}

	public function test_a_bound_row_in_the_same_environment_resolves(): void {
		$this->register_unready_provider( 'ready-one', true );
		$this->select( 'ready-one' );

		$resolved = HostingProviderFactory::for_mapping(
			$this->mapping( 'ready-one', 'ready-one:team-1:site-1' )
		);

		$this->assertInstanceOf( HostingProvider::class, $resolved );
	}

	public function test_a_bound_row_whose_provider_is_gone_refuses_rather_than_going_manual(): void {
		$resolved = HostingProviderFactory::for_mapping( $this->mapping( 'wordify', 'wordify:team-1:site-1' ) );

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertNotInstanceOf( ManualHostingProvider::class, $resolved );
	}

	public function test_the_registry_is_memoized_until_it_is_reset(): void {
		$first = HostingProviderFactory::registry();

		$this->assertSame( $first, HostingProviderFactory::registry() );

		HostingProviderFactory::reset();

		$this->assertNotSame(
			$first[ HostingProviderFactory::MANUAL ],
			HostingProviderFactory::registry()[ HostingProviderFactory::MANUAL ]
		);
	}

	public function test_configured_wordify_credentials_still_refuse_while_the_transport_is_unverified(): void {
		// Credentials alone are not readiness: with no verified auth header the
		// client cannot send anything, so the provider is not ready — and the
		// answer is a refusal, not a silent demotion to the manual workflow.
		//
		// The credential and the binding come from the two places that own them,
		// so this exercises the same path a connected site takes.
		add_filter( 'pd_hosting_has_credential', '__return_true' );
		add_filter(
			'pd_hosting_credential_store',
			static fn (): \PostDomain\Hosting\HostingCredentialStore => new class() implements \PostDomain\Hosting\HostingCredentialStore {
				public function status(): \PostDomain\Hosting\CredentialStatus {
					return \PostDomain\Hosting\CredentialStatus::configured( \PostDomain\Hosting\CredentialSource::DATABASE, null );
				}

				public function reveal(): ?\PostDomain\Hosting\CredentialSecret {
					return new \PostDomain\Hosting\CredentialSecret( 'wfy_test_0000000000000000' );
				}

				public function put( \PostDomain\Hosting\CredentialSecret $secret ): void {
					unset( $secret );
				}

				public function forget(): void {}

				public function binding(): ?string {
					return null;
				}

				public function remember_binding( string $environment_id ): void {
					unset( $environment_id );
				}
			}
		);

		\PostDomain\Hosting\HostingBinding::store(
			array(
				'team_id'      => 'team-1',
				'site_id'      => '01HQ0000000000000000000001',
				'validated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->select( 'wordify' );

		$resolved = HostingProviderFactory::for_new_mapping();

		$this->assertInstanceOf( HostingProviderUnavailable::class, $resolved );
		$this->assertNotInstanceOf( ManualHostingProvider::class, $resolved );
		$this->assertSame( 'hosting_provider_not_ready', $resolved->reason );

		remove_all_filters( 'pd_hosting_has_credential' );
		remove_all_filters( 'pd_hosting_credential_store' );
		\PostDomain\Hosting\HostingBinding::forget();
	}

	public function test_the_manual_provider_cannot_be_removed_by_a_filter(): void {
		add_filter( 'pd_hosting_providers', static fn (): array => array() );
		HostingProviderFactory::reset();

		$this->assertInstanceOf(
			ManualHostingProvider::class,
			HostingProviderFactory::registry()[ HostingProviderFactory::MANUAL ]
		);
	}
}
