<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Rest;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Rest\Guard;
use PostDomain\Rest\MappingSerializer;
use PostDomain\Ssl\Environment;
use PostDomain\Support\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

final class SerializerTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new DbRepository();
		register_post_type(
			'club',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'clubs',
			)
		);
	}

	public function tear_down(): void {
		unregister_post_type( 'club' );
		remove_all_filters( 'pd_mapping_is_active' );
		remove_all_filters( 'pd_rest_capability' );
		parent::tear_down();
	}

	private function mapping( VerificationState $v, ActivationState $a, string $type = 'club' ): Mapping {
		return $this->repo->save(
			new Mapping(
				0,
				'xn--mnchen-3ya.example',
				null,
				self::factory()->post->create(
					array(
						'post_type'   => $type,
						'post_status' => 'publish',
					)
				),
				1,
				$v,
				$a,
				SslState::ACTIVE,
				null,
				str_repeat( 'a', 32 ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'cloudflare-saas',
				'cf-zone:zone-1',
				'ref-1'
			)
		);
	}

	public function test_the_host_is_ascii_and_the_display_form_is_unicode(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'xn--mnchen-3ya.example', $resource['host'] );
		$this->assertSame( 'münchen.example', $resource['host_display'] );
	}

	public function test_the_challenge_is_exposed(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::PENDING, ActivationState::INACTIVE ) );

		$this->assertStringContainsString(
			'post-domain-verify=',
			$resource['dns_challenge']['value'],
			'the challenge is a public DNS value, not a credential'
		);
	}

	public function test_no_credential_or_lease_token_appears(): void {
		$encoded = (string) wp_json_encode(
			MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) )
		);

		foreach ( array( 'api_token', 'ssl_mutation_token', 'lease_token', 'owner_installation_id' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded );
		}
	}

	public function test_the_environment_identity_is_the_only_provider_detail_exposed(): void {
		update_option( 'pd_ssl_credentials', array( 'api_token' => 'cf-token-value' ), false );

		$encoded = (string) wp_json_encode(
			MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) )
		);

		$this->assertStringNotContainsString( 'cf-token-value', $encoded );

		delete_option( 'pd_ssl_credentials' );
	}

	public function test_ownership_is_reported_as_a_boolean_not_an_installation_id(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertTrue( $resource['ssl']['owned_by_this_installation'] );
		$this->assertSame( 'created', $resource['ssl']['ownership_origin'] );
	}

	public function test_target_links_come_from_the_post_type(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'club', $resource['target']['post_type'] );
		$this->assertSame( 'clubs', $resource['target']['rest_base'] );
		$this->assertStringContainsString( '/wp/v2/clubs/', (string) $resource['target']['rest_link'] );
	}

	public function test_a_non_rest_post_type_has_no_rest_link(): void {
		register_post_type(
			'private_thing',
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		$resource = MappingSerializer::resource(
			$this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE, 'private_thing' )
		);

		$this->assertNull( $resource['target']['rest_link'] );

		unregister_post_type( 'private_thing' );
	}

	public function test_serving_reports_serving_for_a_healthy_mapping(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'serving', $resource['serving']['state'] );
	}

	public function test_serving_reports_unverified_first(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::PENDING, ActivationState::INACTIVE ) );

		$this->assertSame( 'unverified', $resource['serving']['state'], 'precedence: unverified before inactive' );
	}

	public function test_serving_reports_inactive(): void {
		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::INACTIVE ) );

		$this->assertSame( 'inactive', $resource['serving']['state'] );
	}

	public function test_serving_reports_vetoed(): void {
		add_filter( 'pd_mapping_is_active', '__return_false' );

		$resource = MappingSerializer::resource( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertSame( 'vetoed', $resource['serving']['state'] );
	}

	public function test_serving_reports_broken_for_a_missing_target(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		wp_delete_post( (int) $mapping->post_id, true );

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'broken', $resource['serving']['state'] );
	}

	public function test_a_collection_row_omits_serving_and_the_plan(): void {
		$row = MappingSerializer::row( $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE ) );

		$this->assertArrayNotHasKey( 'serving', $row );
		$this->assertArrayNotHasKey( 'validation_plan', $row );
		$this->assertArrayHasKey( 'verification', $row );
	}

	public function test_a_mutation_in_progress_reports_kind_and_phase_but_not_the_token(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'      => str_repeat( '6', 32 ),
				'ssl_mutation_kind'       => 'remove',
				'ssl_mutation_phase'      => 'in_flight',
				'ssl_mutation_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			),
			array( 'id' => $mapping->id )
		);

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'remove', $resource['ssl']['mutation_in_progress']['kind'] );
		$this->assertSame( 'in_flight', $resource['ssl']['mutation_in_progress']['phase'] );
		$this->assertArrayNotHasKey( 'token', $resource['ssl']['mutation_in_progress'] );
	}

	public function test_a_mutation_in_progress_names_the_environment_it_began_against(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_mutation_token'       => str_repeat( '6', 32 ),
				'ssl_mutation_kind'        => 'create',
				'ssl_mutation_phase'       => 'recovering',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 60 ),
				'ssl_mutation_driver'      => 'cloudflare-saas',
				'ssl_mutation_environment' => 'cf-zone:zone-1',
			),
			array( 'id' => $mapping->id )
		);

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'cloudflare-saas', $resource['ssl']['mutation_in_progress']['driver'] );
		$this->assertSame( 'cf-zone:zone-1', $resource['ssl']['mutation_in_progress']['environment'] );
	}

	public function test_the_resource_environment_is_reported_separately_from_the_mutation_environment(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		// The resource lives in zone A; a method change is in flight in zone A too,
		// but they are different facts with different lifetimes.
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array(
				'ssl_provider_environment' => 'cf-zone:zone-a',
				'ssl_mutation_token'       => str_repeat( '6', 32 ),
				'ssl_mutation_kind'        => 'method',
				'ssl_mutation_phase'       => 'in_flight',
				'ssl_mutation_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 60 ),
				'ssl_mutation_driver'      => 'cloudflare-saas',
				'ssl_mutation_environment' => 'cf-zone:zone-a',
			),
			array( 'id' => $mapping->id )
		);

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertSame( 'cf-zone:zone-a', $resource['ssl']['provider_environment'] );
		$this->assertSame( 'cf-zone:zone-a', $resource['ssl']['mutation_in_progress']['environment'] );
		$this->assertArrayHasKey( 'environment_reachable', $resource['ssl'] );
	}

	public function test_an_unreadable_environment_is_reported_as_such(): void {
		global $wpdb;

		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		// The binding stays complete — only which environment it names changes.
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::domains_table(),
			array( 'ssl_provider_environment' => 'cf-zone:a-zone-nobody-configured' ),
			array( 'id' => $mapping->id )
		);

		$resource = MappingSerializer::resource( $this->repo->by_id( $mapping->id ) );

		$this->assertFalse( $resource['ssl']['environment_reachable'] );
	}

	public function test_the_etag_carries_the_revision(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );

		$this->assertSame( sprintf( '"%d-%d"', $mapping->id, $mapping->revision ), Guard::etag( $mapping ) );
	}

	public function test_a_missing_if_match_is_a_428_error(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$error   = Guard::check_precondition( new WP_REST_Request( 'PATCH', '/x' ), $mapping );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 428, $error->get_error_data()['status'] );
	}

	public function test_a_stale_if_match_is_a_412_error(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$request = new WP_REST_Request( 'PATCH', '/x' );
		$request->set_header( 'if_match', '"' . $mapping->id . '-99"' );

		$error = Guard::check_precondition( $request, $mapping );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 412, $error->get_error_data()['status'] );
	}

	public function test_a_current_if_match_passes(): void {
		$mapping = $this->mapping( VerificationState::VERIFIED, ActivationState::ACTIVE );
		$request = new WP_REST_Request( 'PATCH', '/x' );
		$request->set_header( 'if_match', Guard::etag( $mapping ) );

		$this->assertTrue( Guard::check_precondition( $request, $mapping ) );
	}

	public function test_an_empty_filtered_capability_falls_back_to_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'pd_rest_capability', static fn(): string => '' );

		$this->assertInstanceOf(
			\WP_Error::class,
			Guard::may_manage( new WP_REST_Request( 'GET', '/x' ) )
		);
	}
}
