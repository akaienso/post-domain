<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Ssl\DnsRecordSpec;
use PostDomain\Ssl\DnsRequirementSet;
use PostDomain\Ssl\HttpRequirementSet;
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Verification\Challenge;

final class DomainDetail {

	private const PURPOSE_HEADINGS = array(
		'ownership'          => 'Ownership (post-domain)',
		'provider_ownership' => 'Hostname ownership (provider)',
		'ssl_validation'     => 'Certificate validation (provider)',
		'routing'            => 'Routing',
	);

	public const PRECONDITIONS = array(
		'environment_resolved',
		'driver_registered',
		'identity_confirmed',
		'ownership_authority',
		'fresh_proof',
		'lease_acquired',
	);

	/**
	 * The whole, purpose-grouped rendering of what an operator must publish.
	 *
	 * This is the single rendering of the plan: it carries the plugin's own
	 * permanent ownership TXT as well as everything the driver contributed, so a
	 * caller never needs a second aggregate table that would repeat the challenge
	 * record. The mapping is optional context, not a second source of truth: it
	 * supplies the ownership record when the driver contributed none, and the
	 * persisted SSL state that decides whether a provider wait is real.
	 */
	public static function render_plan( ValidationPlan $plan, ?Mapping $mapping = null ): string {
		$html = '';

		foreach ( self::PURPOSE_HEADINGS as $purpose => $heading ) {
			$sets = $plan->dns[ $purpose ] ?? array();

			if ( 'ownership' === $purpose ) {
				$sets = self::with_core_ownership( $sets, $mapping );
			}

			$http = array_filter( $plan->http, static fn( HttpRequirementSet $h ): bool => $h->purpose === $purpose );

			if ( array() === $sets && array() === $http ) {
				continue;
			}

			$html .= '<h3>' . esc_html( $heading ) . '</h3>';

			if ( 'ownership' === $purpose ) {
				$html .= '<p class="pd-permanent">'
					. esc_html__( 'Permanent: this record must never be removed while the domain is mapped. Re-checks and certificate deletions both re-read it.', 'post-domain' )
					. '</p>';
			}

			if ( 'provider_ownership' === $purpose ) {
				$html .= '<p class="pd-removable">'
					. esc_html__( 'Temporary: this may be removed once the provider reports the hostname active.', 'post-domain' )
					. '</p>';
			}

			if ( 'ssl_validation' === $purpose ) {
				$html .= '<p class="pd-provider-controlled">'
					. esc_html__(
						'Provider-controlled: the certificate authority issues this. Keep it until the certificate is active, and expect it to be needed again at renewal.',
						'post-domain'
					)
					. '</p>';
			}

			if ( 'routing' === $purpose ) {
				$html .= '<p class="pd-permanent">'
					. esc_html__( 'Permanent: this record is what serves the domain and must stay while the mapping exists.', 'post-domain' )
					. '</p>';
			}

			if ( $plan->alternatives_for( $purpose ) ) {
				$html .= '<p>' . esc_html__( 'Create any one of these.', 'post-domain' ) . '</p>';
			}

			foreach ( $sets as $set ) {
				$html .= '<h4>' . esc_html( $set->label ) . '</h4>'
					. '<table class="widefat"><thead><tr><th>'
					. esc_html__( 'Type', 'post-domain' ) . '</th><th>'
					. esc_html__( 'Name', 'post-domain' ) . '</th><th>'
					. esc_html__( 'Value', 'post-domain' ) . '</th></tr></thead><tbody>';

				foreach ( $set->records as $record ) {
					$html .= sprintf(
						'<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
						esc_html( $record->type ),
						esc_html( $record->name ),
						esc_html( $record->value )
					);
				}

				$html .= '</tbody></table>';
			}

			foreach ( $http as $token ) {
				$html .= '<h4>' . esc_html( $token->label ) . '</h4><p>'
					. esc_html__( 'Serve this at the URL below. It is an HTTP token, not a DNS record.', 'post-domain' )
					. '</p><p><code>' . esc_html( $token->url ) . '</code><br><code>'
					. esc_html( $token->body ) . '</code></p>';
			}
		}

		foreach ( $plan->manual as $manual ) {
			$html .= '<h3>' . esc_html( $manual->label ) . '</h3><p>'
				. esc_html( $manual->instruction ) . ' '
				. esc_html__( 'This step cannot be automated.', 'post-domain' ) . '</p><ul>';

			foreach ( $manual->contacts as $contact ) {
				$html .= '<li><code>' . esc_html( $contact ) . '</code></li>';
			}

			$html .= '</ul>';
		}

		if ( array() !== $plan->pending && self::provider_wait_is_real( $mapping ) ) {
			$html .= '<h3>' . esc_html__( 'Awaiting provider', 'post-domain' ) . '</h3><p>'
				. esc_html__( 'The provider has not issued these records yet. This is a wait, not a failure.', 'post-domain' )
				. '</p>';
		}

		if ( array() !== $plan->blockers ) {
			$html .= '<h3>' . esc_html__( 'Blockers', 'post-domain' ) . '</h3>';

			foreach ( $plan->blockers as $blocker ) {
				$html .= '<p class="pd-blocker"><strong>' . esc_html( $blocker->message ) . '</strong><br>'
					. esc_html( $blocker->remedy ) . '</p>';
			}
		}

		return $html;
	}

	/**
	 * The permanent ownership TXT, rendered exactly once.
	 *
	 * The record reaches the driver as the core record name and value, so a driver
	 * normally returns it under the `ownership` purpose. A driver that contributes
	 * nothing — the null driver, or an unavailable one — must not cost the operator
	 * the one record they always have to publish, so it is rebuilt from the mapping
	 * here. Any copy already present is dropped first: the record renders once, no
	 * matter how many sources offered it.
	 *
	 * @param DnsRequirementSet[] $sets
	 *
	 * @return DnsRequirementSet[]
	 */
	private static function with_core_ownership( array $sets, ?Mapping $mapping ): array {
		if ( null === $mapping ) {
			return $sets;
		}

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $name ) {
			return $sets;
		}

		$value  = Challenge::expected_value( $mapping->challenge );
		$kept   = array();
		$needle = strtolower( $name );

		foreach ( $sets as $set ) {
			$records = array_values(
				array_filter(
					$set->records,
					static fn( DnsRecordSpec $record ): bool => strtolower( $record->name ) !== $needle
				)
			);

			if ( array() === $records ) {
				continue;
			}

			$kept[] = count( $records ) === count( $set->records )
				? $set
				: new DnsRequirementSet(
					$set->purpose,
					$set->id,
					$set->label,
					$records,
					$set->apex_compatible,
					$set->source,
					$set->removable_once_active
				);
		}

		array_unshift(
			$kept,
			new DnsRequirementSet(
				'ownership',
				'core-ownership',
				'Ownership TXT (permanent)',
				array( new DnsRecordSpec( 'TXT', $name, $value ) ),
				true,
				'core',
				false
			)
		);

		return $kept;
	}

	/**
	 * Whether a provider wait describes outstanding work.
	 *
	 * A plan is built from whatever the provider read returned, and an absent
	 * resource looks exactly like one whose records have not been issued yet. The
	 * persisted row is what distinguishes them, so the decision is made here where
	 * that state is in hand: a terminal no-resource state, or a row holding no
	 * provider reference and no mutation lease, has nothing outstanding by
	 * definition. With no mapping in hand there is no state to contradict the plan,
	 * so the plan is trusted.
	 */
	private static function provider_wait_is_real( ?Mapping $mapping ): bool {
		if ( null === $mapping ) {
			return true;
		}

		if ( in_array( $mapping->ssl_state, array( SslState::NONE, SslState::REVOKED ), true ) ) {
			return false;
		}

		return null !== $mapping->ssl_ref || null !== $mapping->ssl_mutation_token;
	}

	/** @param array<string, bool> $results */
	public static function render_deletion_checklist( array $results ): string {
		$html = '<ul class="pd-deletion-checklist">';

		foreach ( self::PRECONDITIONS as $precondition ) {
			$passed = $results[ $precondition ] ?? false;

			$html .= sprintf(
				'<li class="%s"><code>%s</code> — %s</li>',
				$passed ? 'pd-check-passed' : 'pd-check-failed',
				esc_html( $precondition ),
				$passed
					? esc_html__( 'passed', 'post-domain' )
					: esc_html__( 'not satisfied', 'post-domain' )
			);
		}

		return $html . '</ul>';
	}
}
