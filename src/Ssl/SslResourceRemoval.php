<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

use PostDomain\Contracts\Clock;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;

/**
 * `DELETE /domains/{id}/ssl` — authorized removal of the *provider resource*, and
 * nothing else.
 *
 * The mapping is not the certificate. Deleting the row here would destroy the
 * host, the post binding, the verification state and the aliases in order to get
 * rid of a certificate, which is not what the route says it does and not what an
 * operator asks for when they remove SSL from a domain that keeps serving.
 * `DELETE /domains/{id}` remains the only normal route that deletes a mapping,
 * through the §14.15 workflow.
 *
 * The removal itself is the same one: `DeletionAuthorizer`, `MutationGate`,
 * `ExecutionPermit`, and the single finalize CAS, all shared with
 * `DeletionService` through `RemovalWorkflow`. No driver is reached from here.
 */
final class SslResourceRemoval {

	private readonly RemovalWorkflow $workflow;

	public function __construct(
		DeletionAuthorizer $authorizer,
		MutationLease $lease,
		MutationGate $gate,
		Clock $clock
	) {
		$this->workflow = new RemovalWorkflow( $authorizer, $lease, $gate, $clock );
	}

	/**
	 * @return string One of: removed, pending, transient, failed, refused,
	 *                scope_conflict, fenced, deferred.
	 */
	public function process( Mapping $mapping ): string {
		// A mapping already on its way out is not a certificate to strip. Letting
		// this run would quietly downgrade a requested deletion into a keep, and
		// the operator who asked for the domain to go would never learn that it
		// stayed. The lease CAS covers the in-flight case; this covers the
		// requested-but-not-yet-started one.
		//
		// Checked here rather than only in the dispatcher: a service that is safe
		// only when something else routes correctly is not safe. A row whose scope
		// this build cannot read is refused on the same terms — the one thing
		// never to do with an unreadable intent is act on it.
		$scope = $mapping->ssl_removal_scope;

		if ( RemovalScope::MAPPING === RemovalScope::from_row( $scope ) || RemovalScope::is_invalid( $scope ) ) {
			return 'scope_conflict';
		}

		$gated = $this->workflow->attempt( $mapping );

		if ( $gated instanceof MutationRefusal ) {
			return 'refused';
		}

		/** @var RemovalResult $result */
		$result = $gated->result;

		if ( RemovalOutcome::REMOVED === $result->outcome ) {
			$applied = $this->workflow->finalize(
				$mapping,
				$gated,
				self::unbound(),
				'ssl_removed',
				'confirmed'
			);

			return 'committed' === $applied ? 'removed' : $applied;
		}

		// Nothing is cleared while the provider still holds, or may still hold,
		// the resource: an unbound row cannot be asked about it again.
		//
		// The scope is claimed here, inside the owner-pinned finalize CAS, so an
		// unfinished SSL-only removal becomes selectable by the sweep and is
		// resumed through this service rather than through mapping deletion.
		$applied = $this->workflow->finalize(
			$mapping,
			$gated,
			$this->workflow->retry_schedule( $mapping, $result )
				->with( array( 'ssl_removal_scope' => RemovalScope::RESOURCE->value ) ),
			'removal_' . $result->outcome->value,
			$result->outcome->value
		);

		return 'committed' === $applied ? $result->outcome->value : $applied;
	}

	/**
	 * The row keeps its identity and loses its certificate.
	 *
	 * The five binding and ownership columns move together, as they do
	 * everywhere else (§12.6): a row that names a provider must name the
	 * environment, the reference and the owner, and a row that names none of them
	 * must name none of them. The adoption provenance goes with them, because it
	 * describes how this installation came to own a resource that no longer
	 * exists.
	 *
	 * `REVOKED` is the honest terminal state: the resource existed and is gone,
	 * it does not serve, and it is the one non-serving state the enum allows a
	 * later `REQUESTED` from, so the domain can be provisioned again.
	 *
	 * The deletion counters are reset because they belong to the mapping-deletion
	 * workflow, and a mapping that is staying must not be left looking due to it.
	 */
	private static function unbound(): LeaseOutcome {
		return LeaseOutcome::raw(
			array(
				'ssl_state'                 => SslState::REVOKED->value,
				'ssl_provider'              => null,
				'ssl_provider_environment'  => null,
				'ssl_ref'                   => null,
				'ssl_ownership_origin'      => null,
				'ssl_owner_installation_id' => null,
				'ssl_adopted_at'            => null,
				'ssl_adopted_by'            => null,
				'ssl_error'                 => null,
				'ssl_provider_state'        => null,
				'deletion_attempts'         => 0,
				'deletion_next_attempt_at'  => null,
				// Nothing is outstanding any more, so nothing selects this row.
				'ssl_removal_scope'         => null,
				'ssl_checked_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
