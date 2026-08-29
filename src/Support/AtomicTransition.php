<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

/**
 * Pairs one state-changing CAS with its event row.
 *
 * On InnoDB the pair is one transaction: the CAS runs first, the event second,
 * and either both land or neither does. On any other engine there is no
 * transaction and the event is best-effort — but it is still attempted only
 * after the CAS has succeeded, so a zero-row CAS never produces a success event.
 *
 * This is the only sanctioned way to write a transition and its event.
 */
final class AtomicTransition {

	private static bool $in_progress = false;

	public static function is_transactional(): bool {
		return 0 === strcasecmp( Schema::engine(), 'InnoDB' );
	}

	/**
	 * Is a transaction already open on this connection?
	 *
	 * SAVEPOINT succeeds in both states. RELEASE SAVEPOINT succeeds only inside a
	 * transaction; outside one, autocommit has already ended the statement's own
	 * transaction and the savepoint no longer exists. The probe needs no
	 * privilege, works the same on MySQL and MariaDB, and — this is the point —
	 * neither commits nor rolls back anything in either state.
	 *
	 * `$wpdb->last_error` is the signal, so errors are suppressed and the previous
	 * suppression state and error text are restored: a probe must not look like a
	 * failure to whatever ran before it.
	 *
	 * @return bool|null True inside a transaction, false outside one, null when
	 *                   the probe itself could not be trusted.
	 */
	public static function in_ambient_transaction(): ?bool {
		global $wpdb;

		$name       = 'pd_probe_' . bin2hex( random_bytes( 4 ) );
		$suppressed = $wpdb->suppress_errors( true );
		$prior      = $wpdb->last_error;

		$set = $wpdb->query( "SAVEPOINT {$name}" ); // phpcs:ignore WordPress.DB

		if ( false === $set ) {
			$wpdb->suppress_errors( $suppressed );
			$wpdb->last_error = $prior;

			return null;
		}

		$released = $wpdb->query( "RELEASE SAVEPOINT {$name}" ); // phpcs:ignore WordPress.DB

		$wpdb->suppress_errors( $suppressed );
		$wpdb->last_error = $prior;

		// Released cleanly => the savepoint outlived its statement => a
		// transaction is open. Refused => there was none.
		return false !== $released;
	}

	/**
	 * @param callable(): bool $transition The owner-pinned CAS. True ⇒ exactly one row changed.
	 * @param callable(): bool $event      Records the event. True ⇒ the row was inserted.
	 */
	public static function commit( callable $transition, callable $event ): TransitionResult {
		global $wpdb;

		// Nesting would make the inner COMMIT close the outer transaction, so the
		// outer caller would be told its work committed when it had not.
		if ( self::$in_progress ) {
			throw new \LogicException( 'AtomicTransition::commit() may not be nested.' );
		}

		if ( ! self::is_transactional() ) {
			if ( ! $transition() ) {
				return new TransitionResult( TransitionOutcome::CAS_LOST, 'the CAS affected no rows' );
			}

			// Best-effort, and only ever after the fact. A missing event on a
			// nontransactional engine is tolerable: nothing reads the log.
			return $event()
				? new TransitionResult( TransitionOutcome::COMMITTED, 'committed without a transaction' )
				: new TransitionResult( TransitionOutcome::COMMITTED, 'committed; the best-effort event was not recorded' );
		}

		// START TRANSACTION inside somebody else's transaction implicitly commits
		// it. Refuse rather than take a write we do not own with us (spec §12.3).
		$ambient = self::in_ambient_transaction();

		if ( null === $ambient ) {
			return new TransitionResult(
				TransitionOutcome::TRANSACTION_UNAVAILABLE,
				'the session transaction state could not be determined'
			);
		}

		if ( true === $ambient ) {
			return new TransitionResult(
				TransitionOutcome::TRANSACTION_UNAVAILABLE,
				'a transaction opened elsewhere is already active on this connection'
			);
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB
			// The transition is never attempted: without the transaction there is
			// no way to keep it and its event together.
			return new TransitionResult( TransitionOutcome::TRANSACTION_UNAVAILABLE, 'START TRANSACTION failed' );
		}

		self::$in_progress = true;

		try {
			if ( ! $transition() ) {
				return self::undo( TransitionOutcome::CAS_LOST, 'the CAS affected no rows' );
			}

			if ( ! $event() ) {
				return self::undo( TransitionOutcome::EVENT_FAILED, 'the event row could not be inserted' );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB
				// The client did not get confirmation. That is NOT the same as
				// knowing the server discarded it — the transaction may well have
				// committed. No rollback is attempted, because rolling back a
				// transaction that already committed is meaningless and rolling
				// back one that did not is what the connection will do anyway.
				//
				// Callers must NOT re-read this connection to decide what happened:
				// while the transaction is unresolved it shows them their own
				// uncommitted writes. A later request, cron pass, or reconciliation
				// — each with its own committed view — is what settles it.
				return new TransitionResult( TransitionOutcome::COMMIT_UNCERTAIN, 'COMMIT was not confirmed; the outcome is unknown' );
			}

			return new TransitionResult( TransitionOutcome::COMMITTED, 'committed' );
		} catch ( \Throwable $e ) {
			self::undo( TransitionOutcome::CAS_LOST, 'rolled back after an exception' );

			throw $e;
		} finally {
			self::$in_progress = false;
		}
	}

	private static function undo( TransitionOutcome $outcome, string $detail ): TransitionResult {
		global $wpdb;

		if ( false === $wpdb->query( 'ROLLBACK' ) ) { // phpcs:ignore WordPress.DB
			// The transition may or may not still stand. That is not a CAS loss.
			return new TransitionResult( TransitionOutcome::COMMIT_UNCERTAIN, $detail . '; ROLLBACK also failed' );
		}

		return new TransitionResult( $outcome, $detail );
	}
}
