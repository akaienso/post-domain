<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Support\Schema;

final class DbRepository implements MappingRepository {

	public function by_host( string $ascii_host ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE host = %s", $ascii_host ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	public function by_id( int $id ): ?Mapping {
		global $wpdb;

		$table = Schema::domains_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return null === $row ? null : Mapping::from_row( $row );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return Mapping[]
	 */
	public function all( array $args = array() ): array {
		global $wpdb;

		unset( $args );
		$table = Schema::domains_table();

		/** @var array<int, array<string, string|null>> $rows */
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( static fn( array $row ): Mapping => Mapping::from_row( $row ), $rows );
	}

	public function save( Mapping $m ): Mapping {
		global $wpdb;

		$this->assert_valid( $m );

		$table = Schema::domains_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = array(
			'host'                      => $m->host,
			'alias_of'                  => $m->alias_of,
			'post_id'                   => $m->post_id,
			'verification_state'        => $m->verification_state->value,
			'activation_state'          => $m->activation_state->value,
			'ssl_state'                 => $m->ssl_state->value,
			'integrity_error'           => $m->integrity_error,
			'challenge'                 => $m->challenge,
			'challenge_label'           => $m->challenge_label,
			'ssl_ownership_origin'      => $m->ssl_ownership_origin?->value,
			'ssl_owner_installation_id' => $m->ssl_owner_installation_id,
			'ssl_provider'              => $m->ssl_provider,
			'ssl_provider_environment'  => $m->ssl_provider_environment,
			'ssl_ref'                   => $m->ssl_ref,
			'ssl_method'                => $m->ssl_method,
			'updated_at'                => $now,
		);

		// ssl_next_attempt_at, ssl_transient_count, ssl_adopted_at, and
		// ssl_adopted_by are deliberately absent: they are written only by the CAS
		// that owns them. An adoption in particular is not something save() may
		// mint — it happens through MutationGate or not at all.
		//
		// The six ssl_mutation_* columns are absent for a stronger reason. They
		// are owned outright by MutationLease and its exact CAS operations, and
		// they describe an operation that may already have been sent to a
		// provider. An ordinary update that carried them would write whatever the
		// caller happened to hold — and a Mapping rebuilt from a PATCH body holds
		// six nulls — silently destroying the fencing token and the recovery
		// record for a mutation still in flight. A generic save must be incapable
		// of clearing a lease, and equally incapable of minting one. New rows take
		// the schema's own null defaults.

		if ( 0 === $m->id ) {
			$data['revision']   = 1;
			$data['created_at'] = $now;

			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB

			$saved = $this->by_id( (int) $wpdb->insert_id );

			if ( null === $saved ) {
				throw new \RuntimeException( 'The inserted mapping could not be read back.' );
			}

			return $saved;
		}

		$existing = $this->by_id( $m->id );

		if ( null === $existing ) {
			throw new InvalidMapping( 'Cannot update a mapping that does not exist.' );
		}

		$this->assert_transitions( $existing, $m );

		$sets   = array();
		$values = array();

		foreach ( $data as $column => $value ) {
			// wpdb::prepare() casts a null bound to %s into the empty string, so a
			// nullable column could never be cleared through a placeholder. The
			// column names come from the fixed list above, never from a caller.
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";

				continue;
			}

			$sets[]   = "{$column} = %s";
			$values[] = $value;
		}

		// The window a race has to open in. Nothing production listens to it; it
		// exists so a test can take a lease exactly here, between the caller's
		// read and the CAS, which is the interleaving the CAS is for.
		do_action( 'pd_test_before_repository_update', $m->id );

		// `ssl_mutation_token IS NULL` is part of the CAS, not a check before it.
		// A lease acquired between the caller's read and this statement makes the
		// update match zero rows, so the write loses rather than racing the lease.
		$sql = "UPDATE {$table} SET " . implode( ', ', $sets )
			. ', revision = revision + 1'
			. ' WHERE id = %d AND revision = %d AND ssl_mutation_token IS NULL';

		$values[] = $m->id;
		$values[] = $m->revision;

		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

		if ( 1 !== $affected ) {
			// Which of the two it was is decided by re-reading, so the caller is
			// told the actual reason: a stale revision is the caller's to retry,
			// a lease is not.
			$current = $this->by_id( $m->id );

			if ( null !== $current && null !== $current->ssl_mutation_token ) {
				throw new MutationInProgress(
					sprintf( 'Mapping %d is held by a provider mutation.', $m->id )
				);
			}

			throw new StaleRevision(
				sprintf( 'Mapping %d changed underneath revision %d.', $m->id, $m->revision )
			);
		}

		$saved = $this->by_id( $m->id );

		if ( null === $saved ) {
			throw new \RuntimeException( 'The updated mapping could not be read back.' );
		}

		return $saved;
	}

	private function assert_valid( Mapping $m ): void {
		if ( null === $m->alias_of && null === $m->post_id ) {
			throw new InvalidMapping( 'A canonical mapping must carry a post_id.' );
		}

		if ( null !== $m->alias_of && null !== $m->post_id ) {
			throw new InvalidMapping( 'An alias mapping must not carry a post_id.' );
		}

		if ( null !== $m->alias_of ) {
			$parent = $this->by_id( $m->alias_of );

			if ( null === $parent ) {
				throw new InvalidMapping( 'An alias must point at an existing mapping.' );
			}

			if ( $parent->is_alias() ) {
				throw new InvalidMapping( 'Aliases may not chain.' );
			}
		}

		// Every lease column moves together, including the durable binding to the
		// driver and provider environment the mutation began against (spec §12.6).
		$lease = array(
			null !== $m->ssl_mutation_token,
			null !== $m->ssl_mutation_kind,
			null !== $m->ssl_mutation_phase,
			null !== $m->ssl_mutation_expires_at,
			null !== $m->ssl_mutation_driver,
			null !== $m->ssl_mutation_environment,
		);

		if ( count( array_unique( $lease ) ) > 1 ) {
			throw new InvalidMapping( 'The six lease columns move together.' );
		}

		// The durable resource binding is one fact in five columns: which driver,
		// which environment, which reference, and on whose authority. A row that
		// keeps ssl_provider without the rest is the shape that lets an ordinary
		// read fall back to current configuration and ask the wrong account about
		// somebody else's certificate (spec §12.6).
		$bound = array(
			null !== $m->ssl_provider,
			null !== $m->ssl_provider_environment,
			null !== $m->ssl_ref,
			null !== $m->ssl_ownership_origin,
			null !== $m->ssl_owner_installation_id,
		);

		if ( count( array_unique( $bound ) ) > 1 ) {
			throw new InvalidMapping(
				'The durable provider binding moves as one: provider, provider environment, ref, ownership origin, and owner installation.'
			);
		}

		// Spec §12.2: adopted => ssl_adopted_at IS NOT NULL; created => it is NULL.
		if ( OwnershipOrigin::ADOPTED === $m->ssl_ownership_origin && null === $m->ssl_adopted_at ) {
			throw new InvalidMapping( 'An adopted binding records when it was adopted.' );
		}

		if ( OwnershipOrigin::CREATED === $m->ssl_ownership_origin && null !== $m->ssl_adopted_at ) {
			throw new InvalidMapping( 'A created binding was never adopted.' );
		}
	}

	private function assert_transitions( Mapping $from, Mapping $to ): void {
		if ( ! $from->verification_state->can_transition_to( $to->verification_state ) ) {
			throw new InvalidMapping(
				sprintf(
					'Illegal verification transition %s -> %s.',
					$from->verification_state->value,
					$to->verification_state->value
				)
			);
		}

		if ( ! $from->ssl_state->can_transition_to( $to->ssl_state ) ) {
			throw new InvalidMapping(
				sprintf( 'Illegal SSL transition %s -> %s.', $from->ssl_state->value, $to->ssl_state->value )
			);
		}
	}

	public function delete( int $id ): void {
		global $wpdb;

		foreach ( $this->all() as $mapping ) {
			if ( $mapping->alias_of === $id ) {
				throw new AliasInUse(
					sprintf( 'Mapping %d still has aliases pointing at it.', $id )
				);
			}
		}

		$wpdb->delete( Schema::domains_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
}
