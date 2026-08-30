<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\DbRepository;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Ssl\BoundResource;
use PostDomain\Ssl\DriverFactory;
use PostDomain\Ssl\DriverUnavailable;
use PostDomain\Ssl\Environment;
use PostDomain\Ssl\SslResourceContext;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;

/**
 * The management screen an operator actually uses.
 *
 * Markup is built here and escaped here, at the point each value is written.
 * It is deliberately not passed through `wp_kses_post()` on the way out:
 * that allowlist is for post content and drops every form control, which is
 * exactly how v1.0.0 shipped a settings page whose provider selector rendered
 * as run-together text.
 */
final class Screen {

	/**
	 * Which post types an operator may map a domain to.
	 *
	 * @return string[]
	 */
	public static function target_post_types(): array {
		/** @var string[] $types */
		$types = (array) apply_filters(
			'pd_admin_target_post_types',
			array_values( get_post_types( array( 'public' => true ), 'names' ) )
		);

		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	public static function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Domain mappings', 'post-domain' ) . '</h1>';

		// Already-escaped, plugin-owned markup.
		echo Notices::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$mapping = self::requested_mapping();

		if ( null !== $mapping ) {
			echo self::detail( $mapping ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';

			return;
		}

		echo self::driver_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::add_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::list_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	private static function requested_mapping(): ?Mapping {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET that only selects which row to display.
		$id = isset( $_GET['mapping'] ) ? absint( wp_unslash( $_GET['mapping'] ) ) : 0;

		return 0 === $id ? null : ( new DbRepository() )->by_id( $id );
	}

	private static function form_open( string $action, int $mapping_id = 0, int $revision = 0 ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ) . '">';
		$html .= wp_nonce_field( Actions::nonce_action( $action, $mapping_id ), '_wpnonce', true, false );
		$html .= '<input type="hidden" name="pd_action" value="' . esc_attr( $action ) . '">';

		if ( $mapping_id > 0 ) {
			$html .= '<input type="hidden" name="pd_mapping" value="' . esc_attr( (string) $mapping_id ) . '">';
			$html .= '<input type="hidden" name="pd_revision" value="' . esc_attr( (string) $revision ) . '">';
		}

		return $html;
	}

	public static function driver_form(): string {
		$selected = DriverFactory::selected_driver_id();
		$ids      = DriverFactory::registry()->ids();

		$html  = '<h2>' . esc_html__( 'Certificate provider', 'post-domain' ) . '</h2>';
		$html .= self::form_open( 'pd_set_driver' );
		$html .= '<p><label for="pd_ssl_driver">'
			. esc_html__( 'Provider', 'post-domain' ) . '</label><br>';
		$html .= '<select name="pd_ssl_driver" id="pd_ssl_driver">';

		foreach ( $ids as $id ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $selected, $id, false ),
				esc_html( Labels::driver( $id ) )
			);
		}

		$html .= '</select></p>';

		if ( ! in_array( $selected, $ids, true ) ) {
			// Named plainly rather than silently corrected: the stored value is
			// still what the site asked for, and the operator should see that.
			$html .= '<p class="notice notice-error"><span>' . sprintf(
				/* translators: %s: the configured provider identifier. */
				esc_html__( 'The configured provider "%s" is not available. Certificates will not be requested until this is resolved.', 'post-domain' ),
				esc_html( $selected )
			) . '</span></p>';
		}

		if ( ! in_array( 'cloudflare-saas', $ids, true ) ) {
			$html .= '<p class="description">' . esc_html__(
				'Cloudflare for SaaS appears here once its API token, zone, and hostname target are configured.',
				'post-domain'
			) . '</p>';
		}

		$html .= get_submit_button( __( 'Save provider', 'post-domain' ), 'secondary', 'submit', false );
		$html .= '</form>';

		return $html;
	}

	public static function add_form(): string {
		$html  = '<h2>' . esc_html__( 'Add a domain', 'post-domain' ) . '</h2>';
		$html .= self::form_open( 'pd_add_mapping' );
		$html .= '<table class="form-table" role="presentation"><tbody>';

		$html .= '<tr><th scope="row"><label for="pd_host">'
			. esc_html__( 'Domain name', 'post-domain' ) . '</label></th><td>';
		$html .= '<input type="text" class="regular-text" name="pd_host" id="pd_host" '
			. 'placeholder="club.example.org" autocomplete="off" required>';
		$html .= '<p class="description">'
			. esc_html__( 'The hostname visitors will type. Do not include http:// or a path.', 'post-domain' )
			. '</p></td></tr>';

		$html .= '<tr><th scope="row"><label for="pd_post_id">'
			. esc_html__( 'Shows this content', 'post-domain' ) . '</label></th><td>';
		$html .= self::target_select();
		$html .= '<p class="description">'
			. esc_html__( 'The page or post this domain opens on. Anything beneath it is served too.', 'post-domain' )
			. '</p></td></tr>';

		$html .= '</tbody></table>';
		$html .= get_submit_button( __( 'Add domain', 'post-domain' ), 'primary', 'submit', false );
		$html .= '</form>';

		return $html;
	}

	private static function target_select(): string {
		$html  = '<select name="pd_post_id" id="pd_post_id" required>';
		$html .= '<option value="">' . esc_html__( '— Choose —', 'post-domain' ) . '</option>';

		foreach ( self::target_post_types() as $type ) {
			$object = get_post_type_object( $type );
			$posts  = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => array( 'publish', 'private' ),
					// Bounded deliberately: a selector, not an export.
					'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);

			if ( array() === $posts ) {
				continue;
			}

			$html .= '<optgroup label="' . esc_attr( $object?->labels->name ?? $type ) . '">';

			foreach ( $posts as $post ) {
				$title = '' === trim( $post->post_title )
					? sprintf( /* translators: %d: post id. */ __( '(no title) #%d', 'post-domain' ), $post->ID )
					: $post->post_title;

				$html .= sprintf(
					'<option value="%d">%s</option>',
					(int) $post->ID,
					esc_html( $title )
				);
			}

			$html .= '</optgroup>';
		}

		$html .= '</select>';

		return $html;
	}

	public static function list_table(): string {
		$rows = ( new DbRepository() )->all();
		$idna = new IdnaNormalizer();

		$html = '<h2>' . esc_html__( 'Mapped domains', 'post-domain' ) . '</h2>';

		if ( array() === $rows ) {
			// An empty table with a header row tells an operator nothing.
			return $html . '<p>' . esc_html__(
				'No domains are mapped yet. Add one above to get started.',
				'post-domain'
			) . '</p>';
		}

		$html .= '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'Domain', 'post-domain' ),
				__( 'Shows', 'post-domain' ),
				__( 'Verification', 'post-domain' ),
				__( 'Serving', 'post-domain' ),
				__( 'Certificate', 'post-domain' ),
			) as $heading
		) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $mapping ) {
			$detail = add_query_arg(
				array(
					'page'    => SettingsPage::SLUG,
					'mapping' => $mapping->id,
				),
				admin_url( 'options-general.php' )
			);

			$title = null === $mapping->post_id ? null : get_the_title( $mapping->post_id );

			$html .= '<tr><td><strong><a href="' . esc_url( $detail ) . '">'
				. esc_html( $idna->to_display( $mapping->host ) ) . '</a></strong><br><code>'
				. esc_html( $mapping->host ) . '</code></td>';
			$html .= '<td>' . esc_html( (string) ( $title ?? __( '(alias)', 'post-domain' ) ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::verification( $mapping->verification_state ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::activation( $mapping->activation_state ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::ssl( $mapping->ssl_state ) ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	public static function detail( Mapping $mapping ): string {
		$idna = new IdnaNormalizer();
		$back = add_query_arg( array( 'page' => SettingsPage::SLUG ), admin_url( 'options-general.php' ) );

		$html  = '<p><a href="' . esc_url( $back ) . '">'
			. esc_html__( '← All domains', 'post-domain' ) . '</a></p>';
		$html .= '<h2>' . esc_html( $idna->to_display( $mapping->host ) ) . '</h2>';
		$html .= '<p class="description">' . esc_html( Labels::next_step( $mapping ) ) . '</p>';

		$html .= '<table class="widefat"><tbody>';
		$html .= self::status_row( __( 'Verification', 'post-domain' ), Labels::verification( $mapping->verification_state ), $mapping->verification_state->value );
		$html .= self::status_row( __( 'Serving', 'post-domain' ), Labels::activation( $mapping->activation_state ), $mapping->activation_state->value );
		$html .= self::status_row( __( 'Certificate', 'post-domain' ), Labels::ssl( $mapping->ssl_state ), $mapping->ssl_state->value );
		$html .= '</tbody></table>';

		$html .= self::ownership( $mapping );
		$html .= self::dns_requirements( $mapping );
		$html .= self::controls( $mapping );

		return $html;
	}

	private static function status_row( string $label, string $plain, string $technical ): string {
		return '<tr><th scope="row" style="width:12em">' . esc_html( $label ) . '</th><td>'
			. esc_html( $plain )
			. ' <code style="opacity:.6">' . esc_html( $technical ) . '</code></td></tr>';
	}

	private static function ownership( Mapping $mapping ): string {
		$html = '<h3>' . esc_html__( 'Ownership', 'post-domain' ) . '</h3><p>';

		if ( null === $mapping->ssl_provider ) {
			return $html . esc_html__( 'No certificate is held for this domain.', 'post-domain' ) . '</p>';
		}

		$mine = Environment::installation_id() === $mapping->ssl_owner_installation_id;

		$html .= $mine
			? esc_html__( 'This installation holds the certificate for this domain.', 'post-domain' )
			: esc_html__( 'Another installation holds the certificate for this domain. It cannot be changed here.', 'post-domain' );

		$html .= '<br>' . sprintf(
			/* translators: 1: provider name, 2: provider environment. */
			esc_html__( 'Provider: %1$s, environment %2$s', 'post-domain' ),
			esc_html( Labels::driver( (string) $mapping->ssl_provider ) ),
			esc_html( (string) $mapping->ssl_provider_environment )
		);

		return $html . '</p>';
	}

	/** The records the operator has to publish, including the ownership TXT. */
	private static function dns_requirements( Mapping $mapping ): string {
		$html = '<h3>' . esc_html__( 'DNS records to publish', 'post-domain' ) . '</h3>';

		$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null !== $name ) {
			$html .= '<table class="widefat"><thead><tr>'
				. '<th>' . esc_html__( 'Type', 'post-domain' ) . '</th>'
				. '<th>' . esc_html__( 'Name', 'post-domain' ) . '</th>'
				. '<th>' . esc_html__( 'Value', 'post-domain' ) . '</th>'
				. '</tr></thead><tbody><tr><td>TXT</td><td><code>'
				. esc_html( $name ) . '</code></td><td><code>'
				. esc_html( Challenge::expected_value( $mapping->challenge ) )
				. '</code></td></tr></tbody></table>';
		}

		$driver = BoundResource::driver_for( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			$html .= '<p>' . esc_html__(
				'Certificate validation records appear once a provider is configured for this domain.',
				'post-domain'
			) . '</p>';

			return $html;
		}

		$record_name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $record_name ) {
			return $html;
		}

		$plan = $driver->validation_plan(
			SslResourceContext::from_mapping( $mapping, Environment::installation_id(), $record_name, $driver->id() ),
			null
		);

		// DomainDetail escapes as it builds; it must not be run through an
		// allowlist that would strip its tables.
		$html .= DomainDetail::render_plan( $plan );

		return $html;
	}

	/** One button per action the state machine actually permits right now. */
	private static function controls( Mapping $mapping ): string {
		$html = '<h3>' . esc_html__( 'Actions', 'post-domain' ) . '</h3>';

		if ( null !== $mapping->ssl_removal_scope ) {
			return $html . '<p>' . esc_html__(
				'This domain is being removed. No further action is available.',
				'post-domain'
			) . '</p>';
		}

		if ( null !== $mapping->ssl_mutation_token ) {
			return $html . '<p>' . esc_html__(
				'A certificate operation is running for this domain. Controls return when it finishes.',
				'post-domain'
			) . '</p>';
		}

		$buttons = array();

		$buttons[] = self::button( 'pd_verify', $mapping, __( 'Check verification', 'post-domain' ), 'secondary' );
		$buttons[] = self::button( 'pd_rotate_challenge', $mapping, __( 'Issue a new verification record', 'post-domain' ), 'secondary' );

		if ( VerificationState::VERIFIED === $mapping->verification_state ) {
			$buttons[] = ActivationState::ACTIVE === $mapping->activation_state
				? self::button( 'pd_deactivate', $mapping, __( 'Stop serving', 'post-domain' ), 'secondary' )
				: self::button( 'pd_activate', $mapping, __( 'Start serving', 'post-domain' ), 'primary' );
		}

		if ( self::may_request_certificate( $mapping ) ) {
			$buttons[] = self::button(
				'pd_provision_ssl',
				$mapping,
				SslState::FAILED === $mapping->ssl_state
					? __( 'Request the certificate again', 'post-domain' )
					: __( 'Request a certificate', 'post-domain' ),
				'primary'
			);
		}

		if ( null !== $mapping->ssl_ref ) {
			$buttons[] = self::button( 'pd_remove_ssl', $mapping, __( 'Remove the certificate', 'post-domain' ), 'secondary' );
		}

		$buttons[] = self::button( 'pd_delete_mapping', $mapping, __( 'Delete this domain', 'post-domain' ), 'delete' );

		// Each control is its own form, so they are siblings in a div rather than
		// block elements inside a paragraph.
		return $html . '<div class="pd-actions" style="display:flex;flex-wrap:wrap;gap:.5rem">'
			. implode( '', $buttons ) . '</div>';
	}

	private static function may_request_certificate( Mapping $mapping ): bool {
		if ( VerificationState::VERIFIED !== $mapping->verification_state ) {
			return false;
		}

		if ( ActivationState::ACTIVE !== $mapping->activation_state ) {
			return false;
		}

		return in_array(
			$mapping->ssl_state,
			array( SslState::NONE, SslState::FAILED, SslState::REVOKED ),
			true
		);
	}

	private static function button( string $action, Mapping $mapping, string $label, string $variant ): string {
		$confirm = 'pd_delete_mapping' === $action
			? ' onclick="return confirm(' . esc_attr(
				(string) wp_json_encode( __( 'Delete this domain mapping? The content itself is not deleted.', 'post-domain' ) )
			) . ');"'
			: '';

		return self::form_open( $action, $mapping->id, $mapping->revision )
			. '<button type="submit" class="button button-' . esc_attr( $variant ) . '"' . $confirm . '>'
			. esc_html( $label )
			. '</button></form>';
	}
}
