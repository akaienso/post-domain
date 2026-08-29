<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Contracts\SslDriver;
use PostDomain\Support\Schema;

/*
 * Every statement in this class is built from string literals, class constants,
 * and column names this file owns; no fragment of SQL comes from a caller. The
 * sniffs below cannot follow a shared WHERE constant or a value list assembled
 * by LeaseOwner, so they are disabled here rather than silenced line by line.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

final class MutationLease {

	/**
	 * Every value a lease owner possesses, pinned in one place so no CAS can
	 * quietly omit one.
	 *
	 * It is always written as `WHERE id = %d ` . self::OWNER_PREDICATE, and its
	 * values are therefore always `$owner->mapping_id` followed by
	 * `$owner->predicate_values()`. The mapping id is NOT part of
	 * predicate_values(), because it belongs to the `id = %d` this constant does
	 * not contain; forgetting it shifts every following value by one, which is a
	 * silent wrong-row write rather than an error.
	 */
	private const OWNER_PREDICATE = 'AND revision = %d
				    AND ssl_mutation_token = %s AND ssl_mutation_kind = %s
				    AND ssl_mutation_phase = %s
				    AND ssl_mutation_driver = %s AND ssl_mutation_environment = %s';

	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Permitted only when the row carries NO lease. Expiry never frees a row for
	 * ordinary work; it transfers the row to LeaseRecovery.
	 *
	 * The durable binding to this driver and provider environment is written
	 * here, before anything can be sent, because a first create still has
	 * ssl_provider = NULL and recovery must not have to guess which environment
	 * received the request (spec §12.6).
	 */
	public function acquire(
		int $mapping_id,
		int $revision,
		MutationKind $kind,
		SslDriver $driver
	): ?LeaseOwner {
		global $wpdb;

		$identity = DriverIdentity::of( $driver );

		if ( $identity instanceof DriverUnavailable ) {
			// A driver whose identifiers will not fit the columns or will not
			// render safely must never reach SQL, an event, or a screen.
			return null;
		}

		$table   = Schema::domains_table();
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + TimingPolicy::lease_ttl() );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = %s, ssl_mutation_kind = %s,
				        ssl_mutation_phase = %s, ssl_mutation_expires_at = %s,
				        ssl_mutation_driver = %s, ssl_mutation_environment = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL",
				$token,
				$kind->value,
				MutationPhase::RESERVED->value,
				$expires,
				$identity->driver_id,
				$identity->environment_id,
				$this->clock->mysql(),
				$mapping_id,
				$revision
			)
		);

		return 1 === $affected
			? new LeaseOwner(
				$mapping_id,
				$revision + 1,
				$token,
				$kind,
				MutationPhase::RESERVED,
				$identity->driver_id,
				$identity->environment_id
			)
			: null;
	}

	/**
	 * The consumption point: RESERVED -> IN_FLIGHT, before any provider call.
	 * Every bound value is re-checked, with null-safe comparisons.
	 *
	 * @return LeaseOwner|null The IN_FLIGHT owner, or null when the provider must not be called.
	 */
	public function consume( LeaseBinding $b ): ?LeaseOwner {
		global $wpdb;

		$table = Schema::domains_table();

		// wpdb::prepare() turns a null bound to %s into the empty string, so a
		// nullable column can never be compared against NULL through a
		// placeholder — `ssl_provider <=> ''` matches nothing and a first create
		// could never be consumed. Each nullable bound value therefore emits
		// either a literal `IS NULL` or a placeholder, never a bound null. The
		// column names are fixed here; only the values come from the binding.
		$nullable = array(
			'ssl_provider'              => $b->provider_id,
			'ssl_ref'                   => $b->provider_ref,
			'ssl_method'                => $b->requested_method,
			'ssl_ownership_origin'      => $b->ownership_origin?->value,
			'ssl_owner_installation_id' => $b->owner_installation_id,
		);

		$conditions = array();
		$bound      = array();

		foreach ( $nullable as $column => $value ) {
			if ( null === $value ) {
				$conditions[] = "AND {$column} IS NULL";

				continue;
			}

			$conditions[] = "AND {$column} = %s";
			$bound[]      = $value;
		}

		$sql = "UPDATE {$table}
			    SET ssl_mutation_phase = %s, revision = revision + 1, updated_at = %s
			  WHERE id = %d AND revision = %d
			    AND ssl_mutation_token = %s AND ssl_mutation_kind = %s
			    AND ssl_mutation_phase = %s AND ssl_mutation_expires_at > %s
			    AND ssl_mutation_driver = %s
			    AND ssl_mutation_environment = %s
			    AND host = %s
			    AND challenge = %s
			    " . implode( "\n\t\t\t    ", $conditions );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				$sql,
				array_merge(
					array(
						MutationPhase::IN_FLIGHT->value,
						$this->clock->mysql(),
						$b->mapping_id,
						$b->revision,
						$b->token,
						$b->kind->value,
						MutationPhase::RESERVED->value,
						$this->clock->mysql(),
						$b->mutation_driver,
						$b->mutation_environment,
						$b->host,
						$b->challenge,
					),
					$bound
				)
			)
		);

		return 1 === $affected
			? new LeaseOwner(
				$b->mapping_id,
				$b->revision + 1,
				$b->token,
				$b->kind,
				MutationPhase::IN_FLIGHT,
				$b->mutation_driver,
				$b->mutation_environment
			)
			: null;
	}

	/** Releases a RESERVED lease after a refusal, before any provider call. */
	public function release_reserved( LeaseOwner $owner ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
				        ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
				        ssl_mutation_driver = NULL, ssl_mutation_environment = NULL,
				        ssl_next_attempt_at = NULL, ssl_transient_count = 0,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d " . self::OWNER_PREDICATE,
				$owner->where_values( array( $this->clock->mysql() ) )
			)
		);
	}

	/** Applies the result and clears the lease in one transition. */
	public function finalize( LeaseOwner $owner, LeaseOutcome $outcome ): bool {
		global $wpdb;

		$table  = Schema::domains_table();
		$sets   = array();
		$values = array();

		foreach ( $outcome->columns() as $column => $value ) {
			// Column names come from LeaseOutcome's allowlist, never from a caller.
			// A null bound through %s would be written as the empty string, so a
			// column an outcome deliberately clears emits a literal NULL instead.
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";

				continue;
			}

			$sets[]   = "{$column} = %s";
			$values[] = $value;
		}

		$sets[] = 'ssl_mutation_token = NULL';
		$sets[] = 'ssl_mutation_kind = NULL';
		$sets[] = 'ssl_mutation_phase = NULL';
		$sets[] = 'ssl_mutation_expires_at = NULL';
		$sets[] = 'ssl_mutation_driver = NULL';
		$sets[] = 'ssl_mutation_environment = NULL';

		// Recovery-only scheduling state belongs to the recovery that is now over.
		// Leaving it behind would let ordinary SSL polling pick the row up on a
		// timestamp that no longer means anything. An outcome that names either
		// column deliberately wins: assigning the same column twice in one SET
		// would silently discard the value the caller asked for.
		$reset = array(
			'ssl_next_attempt_at' => 'NULL',
			'ssl_transient_count' => '0',
		);

		foreach ( $reset as $column => $literal ) {
			if ( ! array_key_exists( $column, $outcome->columns() ) ) {
				$sets[] = "{$column} = {$literal}";
			}
		}

		$sets[] = 'revision = revision + 1';
		$sets[] = 'updated_at = %s';

		$values = $owner->where_values( array_merge( $values, array( $this->clock->mysql() ) ) );

		$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
			. ' WHERE id = %d ' . self::OWNER_PREDICATE;

		return 1 === $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB
	}

	/** Deletes the row, owned by the exact lease. */
	public function delete_row( LeaseOwner $owner ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id = %d " . self::OWNER_PREDICATE,
				$owner->where_values()
			)
		);
	}

	/**
	 * An expired RESERVED lease proves nothing was sent.
	 *
	 * The revision is deliberately absent: this CAS races the owner's own
	 * begin-mutation transition, which bumps it. Every other owned value is
	 * pinned, plus the expiry that makes the row recoverable at all.
	 */
	public function clear_expired_reserved( LeaseOwner $owner ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = NULL, ssl_mutation_kind = NULL,
				        ssl_mutation_phase = NULL, ssl_mutation_expires_at = NULL,
				        ssl_mutation_driver = NULL, ssl_mutation_environment = NULL,
				        ssl_next_attempt_at = NULL, ssl_transient_count = 0,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d
				    AND ssl_mutation_token = %s AND ssl_mutation_kind = %s
				    AND ssl_mutation_phase = %s
				    AND ssl_mutation_driver = %s AND ssl_mutation_environment = %s
				    AND ssl_mutation_expires_at <= %s",
				$this->clock->mysql(),
				$owner->mapping_id,
				$owner->token,
				$owner->kind->value,
				MutationPhase::RESERVED->value,
				$owner->driver,
				$owner->environment,
				$this->clock->mysql()
			)
		);
	}

	/**
	 * Fences the original worker before any provider read.
	 *
	 * The CAS pins the preserved mutation kind, the EXACT source phase, and the
	 * durable driver and environment binding the caller's snapshot carried. A
	 * selector result is a snapshot: by the time the claim runs, any of those may
	 * have moved, and a claim that matched anyway would fence a mutation the
	 * caller cannot resolve (spec §12.6).
	 *
	 * The binding columns are absent from the SET clause, so the recovery owner
	 * inherits the original binding rather than rebinding to current configuration.
	 */
	public function claim_recovery( LeaseOwner $expected ): ?LeaseOwner {
		global $wpdb;

		if ( MutationPhase::IN_FLIGHT !== $expected->phase && MutationPhase::RECOVERING !== $expected->phase ) {
			throw new \LogicException( 'Recovery is claimed only from IN_FLIGHT or RECOVERING.' );
		}

		$table   = Schema::domains_table();
		$new     = bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + TimingPolicy::recovery_grace() );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_token = %s, ssl_mutation_phase = %s,
				        ssl_mutation_expires_at = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d
				    AND ssl_mutation_token = %s AND ssl_mutation_kind = %s
				    AND ssl_mutation_phase = %s
				    AND ssl_mutation_driver = %s AND ssl_mutation_environment = %s
				    AND ssl_mutation_expires_at <= %s",
				$new,
				MutationPhase::RECOVERING->value,
				$expires,
				$this->clock->mysql(),
				$expected->mapping_id,
				$expected->token,
				$expected->kind->value,
				$expected->phase->value,
				$expected->driver,
				$expected->environment,
				$this->clock->mysql()
			)
		);

		if ( 1 !== $affected ) {
			return null;
		}

		$revision = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT revision FROM {$table} WHERE id = %d", $expected->mapping_id ) // phpcs:ignore WordPress.DB
		);

		return new LeaseOwner(
			$expected->mapping_id,
			$revision,
			$new,
			$expected->kind,
			MutationPhase::RECOVERING,
			$expected->driver,
			$expected->environment
		);
	}

	/**
	 * Extends a held RECOVERING lease without changing its owner, and durably
	 * schedules the next bounded re-read.
	 *
	 * The expiry is pushed past the scheduled re-read so the worker that
	 * scheduled it is still the owner when it comes due; a re-read falling
	 * outside the window would hand the row to a takeover instead.
	 */
	public function extend_recovery( LeaseOwner $owner, int $attempt ): bool {
		global $wpdb;

		$table   = Schema::domains_table();
		$backoff = TimingPolicy::recovery_backoff( $attempt );
		$now     = $this->clock->now()->getTimestamp();
		$next    = gmdate( 'Y-m-d H:i:s', $now + $backoff );
		$expires = gmdate( 'Y-m-d H:i:s', $now + TimingPolicy::recovery_grace() + $backoff );

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table}
				    SET ssl_mutation_expires_at = %s, ssl_next_attempt_at = %s,
				        ssl_transient_count = %d, ssl_checked_at = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d " . self::OWNER_PREDICATE,
				$owner->where_values(
					array( $expires, $next, $attempt, $this->clock->mysql(), $this->clock->mysql() )
				)
			)
		);
	}
}
