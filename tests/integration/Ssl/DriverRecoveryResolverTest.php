<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Ssl;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DriverRecoveryResolver;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\MutationKind;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\Ssl\Fixtures\RecordingDriver;
use WP_UnitTestCase;

final class DriverRecoveryResolverTest extends WP_UnitTestCase {

	private DbRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		Environment::remember_primary_host();
		$this->repo = new DbRepository();
	}

	private function resolver(): DriverRecoveryResolver {
		// The resolver takes its driver as an argument now: it never chooses one,
		// so there is nothing to configure here.
		return new DriverRecoveryResolver();
	}

	private int $seq = 0;

	private function mapping( ?string $ref = 'ref-1' ): Mapping {
		// A distinct host per call: host is unique, and a test that recovers more
		// than one kind needs more than one row.
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"mapped-{$this->seq}.test",
				null,
				self::factory()->post->create(),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				SslState::REQUESTED,
				// Challenge is unique too, so it varies with the host.
				null,
				str_pad( (string) $this->seq, 32, 'a', STR_PAD_LEFT ),
				'_post-domain-challenge',
				// The binding moves as one: an unbound row has none of the five.
				null === $ref ? null : OwnershipOrigin::CREATED,
				null === $ref ? null : Environment::installation_id(),
				null === $ref ? null : 'recording',
				null === $ref ? null : 'recording:default',
				$ref
			)
		);
	}

	public function test_create_recovery_binds_a_marker_matched_resource(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping( null ),
			MutationKind::CREATE,
			str_repeat( '1', 32 ),
			RecordingDriver::ambiguous_then_marked( 'ref-9' )
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertFalse( $outcome->delete_row );
		$this->assertNotNull( $outcome->apply );
	}

	public function test_create_recovery_with_conclusive_absence_is_conclusive_and_clears(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping( null ),
			MutationKind::CREATE,
			str_repeat( '1', 32 ),
			RecordingDriver::ambiguous_then_absent()
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertFalse( $outcome->delete_row );
	}

	public function test_create_recovery_without_markers_requires_adoption(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping( null ),
			MutationKind::CREATE,
			str_repeat( '1', 32 ),
			RecordingDriver::ambiguous_then_unmarked( 'ref-9' )
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertStringContainsString( 'adopt', $outcome->note );
	}

	public function test_an_incomplete_read_is_inconclusive_for_every_kind(): void {
		foreach ( MutationKind::cases() as $kind ) {
			$outcome = $this->resolver()->resolve(
				$this->mapping(),
				$kind,
				str_repeat( '1', 32 ),
				RecordingDriver::with_incomplete_identity()
			);

			$this->assertFalse( $outcome->conclusive, "kind {$kind->value} must wait on an incomplete read" );
		}
	}

	public function test_adopt_recovery_confirms_from_identity(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping(),
			MutationKind::ADOPT,
			str_repeat( '1', 32 ),
			RecordingDriver::succeeding( 'ref-1' )
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertNotNull( $outcome->apply );
	}

	public function test_method_recovery_reads_the_confirmed_method(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping(),
			MutationKind::METHOD,
			str_repeat( '1', 32 ),
			RecordingDriver::confirming_method( 'http' )
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertArrayHasKey( 'ssl_method', $outcome->apply->columns() );
	}

	public function test_remove_recovery_deletes_when_the_resource_is_gone(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping(),
			MutationKind::REMOVE,
			str_repeat( '1', 32 ),
			RecordingDriver::ambiguous_then_absent()
		);

		$this->assertTrue( $outcome->conclusive );
		$this->assertTrue( $outcome->delete_row );
	}

	public function test_remove_recovery_keeps_the_row_when_the_resource_still_exists(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping(),
			MutationKind::REMOVE,
			str_repeat( '1', 32 ),
			RecordingDriver::succeeding( 'ref-1' )
		);

		$this->assertFalse( $outcome->delete_row );
	}

	public function test_a_recovered_create_records_the_real_driver_not_a_placeholder(): void {
		// The mapping's stored provider is still NULL here, which is exactly the
		// case that used to write the literal string 'null' into ssl_provider.
		$outcome = $this->resolver()->resolve(
			$this->mapping( null ),
			MutationKind::CREATE,
			str_repeat( '1', 32 ),
			RecordingDriver::ambiguous_then_marked( 'ref-9' )
		);

		$this->assertSame( 'recording', $outcome->apply?->columns()['ssl_provider'] );
	}

	public function test_a_recovered_adoption_records_the_real_driver(): void {
		$outcome = $this->resolver()->resolve(
			$this->mapping( null ),
			MutationKind::ADOPT,
			str_repeat( '1', 32 ),
			RecordingDriver::succeeding( 'ref-1' )
		);

		$this->assertSame( 'recording', $outcome->apply?->columns()['ssl_provider'] );
	}

	public function test_the_resolver_never_chooses_its_own_driver(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Ssl/DriverRecoveryResolver.php' );

		$this->assertStringNotContainsString(
			'DriverFactory',
			$source,
			'the bound driver arrives as an argument; resolving one here would defeat the binding'
		);
	}

	public function test_the_resolver_issues_no_provider_mutation(): void {
		$driver = RecordingDriver::succeeding( 'ref-1' );

		foreach ( MutationKind::cases() as $kind ) {
			$this->resolver()->resolve( $this->mapping(), $kind, str_repeat( '1', 32 ), $driver );
		}

		$this->assertSame( 0, $driver->create_calls );
		$this->assertSame( 0, $driver->adopt_calls );
		$this->assertSame( 0, $driver->method_calls );
		$this->assertSame( 0, $driver->remove_calls );
	}
}
