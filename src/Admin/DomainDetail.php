<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Ssl\HttpRequirementSet;
use PostDomain\Ssl\ValidationPlan;

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

	public static function render_plan( ValidationPlan $plan ): string {
		$html = '';

		foreach ( self::PURPOSE_HEADINGS as $purpose => $heading ) {
			$sets = $plan->dns[ $purpose ] ?? array();
			$http = array_filter( $plan->http, static fn( HttpRequirementSet $h ): bool => $h->purpose === $purpose );

			if ( array() === $sets && array() === $http ) {
				continue;
			}

			$html .= '<h3>' . esc_html( $heading ) . '</h3>';

			if ( 'ownership' === $purpose ) {
				$html .= '<p class="pd-permanent">'
					. esc_html__( 'Permanent: this record must never be removed. Re-checks and certificate deletions both re-read it.', 'post-domain' )
					. '</p>';
			}

			if ( 'provider_ownership' === $purpose ) {
				$html .= '<p class="pd-removable">'
					. esc_html__( 'Temporary: this may be removed once the provider reports the hostname active.', 'post-domain' )
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

		if ( array() !== $plan->pending ) {
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
