<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Admin;

use PostDomain\Admin\Screen;
use PostDomain\Admin\Step;
use PostDomain\Admin\Workflow;
use PostDomain\Application\MappingCommands;
use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\OwnershipOrigin;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\DnsBlocker;
use PostDomain\Ssl\DnsRecordSpec;
use PostDomain\Ssl\DnsRequirementSet;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\ValidationPending;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\Schema;
use PostDomain\Tests\Integration\OwnedSessionTestCase;
use PostDomain\Verification\Cooldown;

/**
 * The cooldown the operator sees, and the steps derived from the provider plan.
 *
 * Two separate defects met here. The screen read WordPress's private
 * transient-timeout option while the command used the Transients API, so behind
 * an object cache the button looked ready and was then refused. And steps 5 and
 * 6 were both marked current for any `REQUESTED` or `PENDING_VALIDATION` row,
 * regardless of which records actually existed.
 */
final class CooldownAndStepsTest extends OwnedSessionTestCase {

	private DbRepository $repo;

	private int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		delete_option( 'pd_environment_mismatch' );
		Environment::remember_primary_host();

		$this->repo = new DbRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function mapping( SslState $ssl = SslState::REQUESTED ): Mapping {
		++$this->seq;

		return $this->repo->save(
			new Mapping(
				0,
				"plan-{$this->seq}.test",
				null,
				self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				1,
				VerificationState::VERIFIED,
				ActivationState::ACTIVE,
				$ssl,
				null,
				str_pad( (string) $this->seq, 32, 'p', STR_PAD_LEFT ),
				'_post-domain-challenge',
				OwnershipOrigin::CREATED,
				Environment::installation_id(),
				'recording',
				'recording:default',
				'ref-1'
			)
		);
	}

	// -- correction 7: one cooldown authority --------------------------------

	public function test_the_command_and_the_screen_read_the_same_cooldown(): void {
		$mapping = $this->mapping();

		$this->assertNull( Screen::verify_available_at( $mapping ) );

		MappingCommands::production( $this->repo )->verify_now( $mapping );

		$shown = Screen::verify_available_at( $mapping );

		$this->assertNotNull( $shown, 'the screen must see the cooldown the command just started' );
		$this->assertTrue( Cooldown::in_force( $mapping->id ) );
		$this->assertGreaterThan( time(), $shown );
	}

	public function test_the_countdown_agrees_with_the_refusal(): void {
		$mapping  = $this->mapping();
		$commands = MappingCommands::production( $this->repo );

		$commands->verify_now( $mapping );

		$second = $commands->verify_now( $mapping );

		$this->assertFalse( $second->succeeded, 'the server refuses' );
		$this->assertNotNull( Screen::verify_available_at( $mapping ), 'and the screen says so' );
	}

	/**
	 * An external object cache serves transients from memory and writes no
	 * `_transient_timeout_*` option at all. Reading that option was how the two
	 * sides came to disagree.
	 */
	public function test_the_cooldown_survives_an_external_object_cache(): void {
		$mapping = $this->mapping();

		wp_using_ext_object_cache( true );
		wp_cache_flush();

		MappingCommands::production( $this->repo )->verify_now( $mapping );

		$shown = Screen::verify_available_at( $mapping );

		wp_using_ext_object_cache( false );

		$this->assertNotNull(
			$shown,
			'with no timeout option written, the screen must still see the cooldown'
		);
		$this->assertFalse(
			(bool) get_option( '_transient_timeout_pd_verify_rate_' . $mapping->id ),
			'and it must not have depended on that option existing'
		);
	}

	public function test_an_elapsed_cooldown_is_over(): void {
		$mapping = $this->mapping();

		set_transient( 'pd_verify_rate_' . $mapping->id, time() - 1, 60 );

		$this->assertNull( Screen::verify_available_at( $mapping ), 'a stored instant in the past is not a wait' );
		$this->assertFalse( Cooldown::in_force( $mapping->id ) );
	}

	// -- correction 5: steps from the plan -----------------------------------

	/** @param array<string, array<int, DnsRequirementSet>> $dns */
	private function plan( array $dns, array $pending = array(), array $blockers = array() ): ValidationPlan {
		return new ValidationPlan( $dns, array(), array(), $pending, $blockers );
	}

	private function set( string $purpose ): DnsRequirementSet {
		return new DnsRequirementSet(
			$purpose,
			$purpose . '-set',
			'A record to publish',
			array( new DnsRecordSpec( 'TXT', 'name.example', 'value' ) ),
			false,
			'recording'
		);
	}

	/** @return array<int, string> */
	private function statuses( Mapping $mapping, ?ValidationPlan $plan ): array {
		$out = array();

		foreach ( Workflow::steps( $mapping, $plan ) as $step ) {
			$out[ $step->number ] = $step->status;
		}

		return $out;
	}

	public function test_only_the_phase_with_a_record_is_current(): void {
		$statuses = $this->statuses(
			$this->mapping(),
			$this->plan( array( 'provider_ownership' => array( $this->set( 'provider_ownership' ) ) ) )
		);

		$this->assertSame( Step::CURRENT, $statuses[5], 'this phase has something to publish' );
		$this->assertNotSame( Step::CURRENT, $statuses[6], 'this one does not' );
	}

	public function test_a_phase_the_provider_has_not_issued_yet_is_a_wait(): void {
		$statuses = $this->statuses(
			$this->mapping(),
			$this->plan(
				array(),
				array( new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' ) )
			)
		);

		$this->assertSame( Step::WAITING, $statuses[6] );
		$this->assertNotSame( Step::CURRENT, $statuses[6] );
	}

	public function test_a_phase_the_provider_has_finished_is_done(): void {
		// Ownership completed and its records disappeared; validation is now asking.
		$statuses = $this->statuses(
			$this->mapping( SslState::PENDING_VALIDATION ),
			$this->plan( array( 'ssl_validation' => array( $this->set( 'ssl_validation' ) ) ) )
		);

		$this->assertSame( Step::DONE, $statuses[5], 'nothing outstanding for this phase any more' );
		$this->assertSame( Step::CURRENT, $statuses[6] );
	}

	public function test_a_blocker_marks_its_phase_as_needing_attention(): void {
		// The phase is named by the blocker, not spelled inside its code: the
		// workflow used to read ownership out of `str_contains( $code, $purpose )`,
		// so a code that did not embed the purpose was silently dropped.
		$statuses = $this->statuses(
			$this->mapping(),
			$this->plan(
				array(),
				array(),
				array( new DnsBlocker( 'method_unsupported', 'That method cannot be used here.', 'Change the method.', 'recording', 'ssl_validation' ) )
			)
		);

		$this->assertSame( Step::FAILED, $statuses[6] );
		$this->assertNotSame( Step::FAILED, $statuses[5], 'and it belongs to one phase only' );
	}

	public function test_a_global_blocker_blocks_every_provider_phase(): void {
		// No purpose: the read itself failed, so nothing at all is known. An empty
		// plan behind a failed read is not evidence that a phase is finished.
		$statuses = $this->statuses(
			$this->mapping(),
			$this->plan(
				array(),
				array(),
				array( new DnsBlocker( 'provider_read_unavailable', 'The provider could not be reached.', 'Try again shortly.', 'recording' ) )
			)
		);

		$this->assertSame( Step::BLOCKED, $statuses[5] );
		$this->assertSame( Step::BLOCKED, $statuses[6] );
	}

	/** The sequence observed in live acceptance testing, in order. */
	public function test_the_live_sequence_reads_correctly_at_each_stage(): void {
		$mapping = $this->mapping();

		// 1. The provider asks for its hostname-ownership record.
		$one = $this->statuses( $mapping, $this->plan( array( 'provider_ownership' => array( $this->set( 'provider_ownership' ) ) ) ) );
		$this->assertSame( Step::CURRENT, $one[5] );

		// 2. Ownership completes; its record disappears, DCV has not appeared yet.
		$two = $this->statuses(
			$mapping,
			$this->plan( array(), array( new ValidationPending( 'ssl_validation', 'provider_records_not_yet_issued' ) ) )
		);
		$this->assertSame( Step::DONE, $two[5] );
		$this->assertSame( Step::WAITING, $two[6] );

		// 3. The certificate-validation record appears.
		$three = $this->statuses( $mapping, $this->plan( array( 'ssl_validation' => array( $this->set( 'ssl_validation' ) ) ) ) );
		$this->assertSame( Step::CURRENT, $three[6] );

		// 4. The certificate goes active.
		$four = $this->statuses( $this->mapping( SslState::ACTIVE ), $this->plan( array() ) );
		$this->assertSame( Step::DONE, $four[5] );
		$this->assertSame( Step::DONE, $four[6] );
		$this->assertSame( Step::DONE, $four[4] );
	}

	// -- routing is not proved by switching serving on -----------------------

	public function test_serving_does_not_prove_the_routing_record_exists(): void {
		$statuses = $this->statuses( $this->mapping( SslState::ACTIVE ), null );

		$this->assertSame(
			Step::CURRENT,
			$statuses[2],
			'clicking Start serving says nothing about DNS'
		);
	}

	public function test_the_routing_step_closes_when_the_domain_actually_answers(): void {
		$mapping = $this->mapping( SslState::ACTIVE );

		Workflow::record_origin_confirmed( $mapping );

		$this->assertSame( Step::DONE, $this->statuses( $mapping, null )[2] );
	}
}
