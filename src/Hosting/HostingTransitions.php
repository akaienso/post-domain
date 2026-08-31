<?php
declare( strict_types = 1 );

namespace PostDomain\Hosting;

use PostDomain\Mapping\EventLog;
use PostDomain\Support\AtomicTransition;
use PostDomain\Support\Schema;

// Every statement below interpolates exactly one identifier, `Schema::domains_table()`,
// which is built from $wpdb->prefix and a constant — never caller input. Every
// value is a placeholder. Same arrangement, and the same reason, as MutationLease.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * The only writer of the five hosting columns.
 *
 * Deliberately not part of `DbRepository::save()`. Those columns describe an
 * operation that may already be in flight at a hosting provider, and a generic
 * update writes whatever the caller happened to hold — a `Mapping` rebuilt from
 * a PATCH body holds five nulls. So `save()` never lists them, exactly as it
 * never lists the SSL mutation columns, and every change to them comes through
 * one of the compare-and-swap operations below.
 *
 * Each CAS pins the mapping id, the expected revision, and — once a row is
 * claimed — the provider, environment and attempt token it was claimed under.
 * That is what makes two concurrent creates produce one attachment call, and
 * what stops a clone or a rebound credential settling somebody else's write.
 *
 * @package PostDomain
 */
final class HostingTransitions {

	/**
	 * Claims a row for exactly one attachment attempt.
	 *
	 * Written before the provider is called, never after: a row left saying
	 * RESERVED is the record that a mutation may have been sent, and it is what
	 * recovery reads to settle. Succeeds only when the row carries no
	 * outstanding hosting attempt, so a second worker racing the first loses
	 * here rather than at the provider.
	 *
	 * @return HostingClaim|null The claim, or null when another worker holds one.
	 */
	public function reserve( int $mapping_id, int $revision, string $provider, string $environment_id ): ?HostingClaim {
		global $wpdb;

		$table   = Schema::domains_table();
		$attempt = bin2hex( random_bytes( 16 ) );
		$now     = gmdate( 'Y-m-d H:i:s' );

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$table}
				    SET hosting_provider = %s, hosting_environment = %s,
				        hosting_state = %s, hosting_ref = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d
				    AND (hosting_state IS NULL OR hosting_state = %s OR hosting_state = %s)",
				$provider,
				$environment_id,
				HostingState::RESERVED->value,
				$attempt,
				$now,
				$mapping_id,
				$revision,
				HostingState::REFUSED->value,
				HostingState::NOT_REQUIRED->value
			)
		);

		return 1 === $affected
			? new HostingClaim( $mapping_id, $revision + 1, $provider, $environment_id, $attempt )
			: null;
	}

	/**
	 * Records that the provider has the hostname on the bound site.
	 *
	 * `hosting_ref` stops being the attempt token and becomes the provider's own
	 * reference, which is the durable thing worth keeping.
	 */
	public function attach( HostingClaim $claim, ?string $reference ): bool {
		return $this->settle(
			$claim,
			HostingState::ATTACHED,
			null === $reference || '' === $reference ? $claim->attempt : $reference,
			gmdate( 'Y-m-d H:i:s' ),
			'hosting.attached'
		);
	}

	/** A write whose fate is unknown. Recovery reads until it is known. */
	public function ambiguous( HostingClaim $claim ): bool {
		return $this->settle( $claim, HostingState::AMBIGUOUS, $claim->attempt, null, 'hosting.ambiguous' );
	}

	/** Terminal, and the operator's to fix: a bad token, or a missing ability. */
	public function refuse( HostingClaim $claim ): bool {
		return $this->settle( $claim, HostingState::REFUSED, null, null, 'hosting.refused' );
	}

	/** The hostname is on another site of the same account. Never adopted. */
	public function foreign( HostingClaim $claim ): bool {
		return $this->settle( $claim, HostingState::FOREIGN, null, null, 'hosting.foreign' );
	}

	/** Read the bounded number of times without settling. Needs a person. */
	public function manual_review( HostingClaim $claim ): bool {
		return $this->settle( $claim, HostingState::MANUAL_REVIEW, $claim->attempt, null, 'hosting.manual_review' );
	}

	/**
	 * Marks a mapping as needing no hosting registration at all.
	 *
	 * The manual provider's ordinary answer. Recorded rather than left null so a
	 * later screen can tell "no provider was involved" from "nothing has
	 * happened yet".
	 */
	public function not_required( int $mapping_id, int $revision, string $provider ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$table}
				    SET hosting_provider = %s, hosting_state = %s,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d AND hosting_state IS NULL",
				$provider,
				HostingState::NOT_REQUIRED->value,
				gmdate( 'Y-m-d H:i:s' ),
				$mapping_id,
				$revision
			)
		);
	}

	/**
	 * Every outstanding registration, oldest first, bounded.
	 *
	 * A selector rather than a scan: recovery must not walk the whole table, and
	 * a run that cannot finish must leave the rest for the next run.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public function outstanding( string $environment_id, int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT id, host, revision, hosting_provider, hosting_environment, hosting_ref, hosting_state, hosting_attempts
				   FROM {$table}
				  WHERE hosting_environment = %s AND hosting_state IN (%s, %s)
			   ORDER BY id ASC
				  LIMIT %d",
				$environment_id,
				HostingState::RESERVED->value,
				HostingState::AMBIGUOUS->value,
				$limit
			),
			ARRAY_A
		);

		return $rows;
	}

	/** Records one recovery read against a claim, bounding the retries. */
	public function count_attempt( HostingClaim $claim ): bool {
		global $wpdb;

		$table = Schema::domains_table();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$table}
				    SET hosting_attempts = hosting_attempts + 1,
				        revision = revision + 1, updated_at = %s
				  WHERE id = %d AND revision = %d
				    AND hosting_provider = %s AND hosting_environment = %s AND hosting_ref = %s",
				gmdate( 'Y-m-d H:i:s' ),
				$claim->mapping_id,
				$claim->revision,
				$claim->provider,
				$claim->environment_id,
				$claim->attempt
			)
		);
	}

	/**
	 * The shared owner-pinned CAS.
	 *
	 * Every value the claim was granted under is re-checked. A credential that
	 * was rebound to a different team, a clone that adopted the row, or a second
	 * worker holding a different attempt token all fail to match, so a stale
	 * result cannot settle a live registration.
	 */
	private function settle(
		HostingClaim $claim,
		HostingState $to,
		?string $reference,
		?string $registered_at,
		string $event
	): bool {
		global $wpdb;

		$table = Schema::domains_table();

		$result = AtomicTransition::commit(
			function () use ( $wpdb, $table, $claim, $to, $reference, $registered_at ): bool {
				$sets   = array( 'hosting_state = %s' );
				$values = array( $to->value );

				if ( null === $reference ) {
					$sets[] = 'hosting_ref = NULL';
				} else {
					$sets[]   = 'hosting_ref = %s';
					$values[] = $reference;
				}

				if ( null !== $registered_at ) {
					$sets[]   = 'hosting_registered_at = %s';
					$values[] = $registered_at;
				}

				$sets[]   = 'revision = revision + 1';
				$sets[]   = 'updated_at = %s';
				$values[] = gmdate( 'Y-m-d H:i:s' );

				$values[] = $claim->mapping_id;
				$values[] = $claim->revision;
				$values[] = $claim->provider;
				$values[] = $claim->environment_id;
				$values[] = $claim->attempt;

				$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
					. ' WHERE id = %d AND revision = %d'
					. ' AND hosting_provider = %s AND hosting_environment = %s AND hosting_ref = %s';

				return 1 === $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB
			},
			static fn (): bool => EventLog::record(
				$claim->mapping_id,
				'',
				$event,
				null,
				$to->value,
				'hosting',
				array( 'environment' => $claim->environment_id )
			)
		);

		return $result->committed();
	}
}
