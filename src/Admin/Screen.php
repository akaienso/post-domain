<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Hosting\HostingBinding;
use PostDomain\Hosting\HostingDetection;
use PostDomain\Hosting\HostingReadiness;
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
use PostDomain\Ssl\ValidationPlan;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Verification\Challenge;
use PostDomain\Verification\Cooldown;

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
	 * Which post types the selector offers.
	 *
	 * A presentation choice, and only that. It narrows what the admin lists; it
	 * does not decide what a mapping may target, because the REST contract
	 * already accepts any readable post — including a private, non-REST custom
	 * type that this list would never show.
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
			echo Guide::render_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';

			return;
		}

		echo self::hosting_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::driver_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::add_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::list_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Guide::render_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	/** How many targets one page of the selector offers. */
	public const TARGETS_PER_PAGE = 50;

	/**
	 * One bounded page of candidate targets.
	 *
	 * Never every post: a selector that silently stops at the first 200 titles
	 * makes every target after them unreachable, with nothing on screen to say
	 * so. This asks for one page at a time and reports the total, so an operator
	 * on an established site can always search their way to the right one.
	 *
	 * @return array{posts: \WP_Post[], total: int, pages: int}
	 */
	public static function target_candidates( string $search, int $page, ?int $limit = null ): array {
		$types = self::target_post_types();

		if ( array() === $types ) {
			return array(
				'posts' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => array( 'publish', 'private' ),
				// `perm => readable` makes the capability part of the SQL, so
				// found_posts and max_num_pages describe the set the operator can
				// actually see. Filtering after the query instead left the counts
				// describing content they cannot read: a page of fifty private
				// posts filtered to nothing, the screen said there was no content
				// at all, and every readable target after it became unreachable.
				'perm'                => 'readable',
				'posts_per_page'      => $limit ?? self::TARGETS_PER_PAGE,
				'paged'               => $page,
				's'                   => $search,
				'orderby'             => '' === $search ? 'modified' : 'relevance',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'suppress_filters'    => false,
			)
		);

		// Defence in depth. `perm` has already excluded what this would exclude,
		// so it normally removes nothing and the counts stay consistent with the
		// list; if a filter ever widened the query, this still refuses to name a
		// post the operator cannot read.
		$readable = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID ) ) {
				$readable[] = $post;
			}
		}

		return array(
			'posts' => $readable,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * The hosting provider, and what it still needs.
	 *
	 * Deliberately above the certificate provider: hosting is the layer that
	 * finally answers with the page, and a domain set up without it verifies,
	 * gets a certificate, and then serves the host's placeholder.
	 */
	public static function hosting_form(): string {
		$detected  = HostingDetection::detect();
		$selected  = HostingDetection::selected();
		$binding   = HostingBinding::current();
		$readiness = HostingReadiness::evaluate();

		$html  = '<h2>' . esc_html__( 'Hosting provider', 'post-domain' ) . '</h2>';
		$html .= '<p class="description">' . esc_html__(
			'Three different things have to know about a mapped domain: your hosting, which finally serves the page; your certificate provider, which answers the secure connection; and your DNS provider, which points the name at them. They are set separately here and need not be the same company.',
			'post-domain'
		) . '</p>';

		if ( array() !== $detected['signals'] && ! HostingDetection::is_chosen_explicitly() ) {
			$html .= '<p class="description">' . esc_html(
				HostingDetection::WORDIFY === $detected['provider']
					? __( 'This looks like a Wordify site, so that path is selected below. Detection only chooses what to show you — it grants no access, and you can change it.', 'post-domain' )
					: __( 'No hosting platform was detected, so the manual path is selected below. You can change it.', 'post-domain' )
			) . '</p>';
		}

		$html .= self::form_open( 'pd_set_hosting' );
		$html .= '<p><label for="pd_hosting_provider">' . esc_html__( 'Hosting', 'post-domain' ) . '</label><br>';
		$html .= '<select name="pd_hosting_provider" id="pd_hosting_provider">';

		foreach (
			array(
				HostingDetection::WORDIFY => __( 'Wordify', 'post-domain' ),
				HostingDetection::MANUAL  => __( 'Manual or another host', 'post-domain' ),
			) as $value => $label
		) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		$html .= '</select> ';
		$html .= get_submit_button( __( 'Save hosting', 'post-domain' ), 'secondary', 'submit', false );
		$html .= '</form>';

		if ( HostingDetection::WORDIFY === $selected ) {
			$html .= self::wordify_connection( $binding );
		} else {
			$html .= '<p class="description">' . esc_html__(
				'Manual hosting: you arrange for your web server to accept the mapped domain yourself. Post Domain will not contact a hosting API.',
				'post-domain'
			) . '</p>';
		}

		if ( ! $readiness->may_add_domains ) {
			$html .= '<div class="notice notice-warning inline"><p><strong>'
				. esc_html( (string) $readiness->blocker ) . '</strong><br>'
				. esc_html( (string) $readiness->remedy ) . '</p></div>';
		}

		return $html;
	}

	/** The Wordify credential and binding, never showing the credential. */
	private static function wordify_connection( HostingBinding $binding ): string {
		$external = (bool) apply_filters( 'pd_hosting_credential_is_external', false );

		$html = '<h3>' . esc_html__( 'Wordify connection', 'post-domain' ) . '</h3>';

		if ( $binding->has_credential() ) {
			// Never the token, and never anything reversible into it.
			$html .= '<p>' . esc_html(
				$external
					? __( 'A Wordify token is configured in wp-config.php. It cannot be changed from here.', 'post-domain' )
					: __( 'A Wordify token is saved. It is stored encrypted and is never shown again.', 'post-domain' )
			) . '</p>';

			if ( $binding->is_bound() ) {
				$html .= '<p>' . sprintf(
					/* translators: 1: Wordify team name, 2: Wordify site name. */
					esc_html__( 'Connected to team %1$s, site %2$s.', 'post-domain' ),
					'<strong>' . esc_html( (string) ( $binding->team_name ?? $binding->team_id ) ) . '</strong>',
					'<strong>' . esc_html( (string) ( $binding->site_name ?? $binding->site_id ) ) . '</strong>'
				) . '</p>';
			}
		} else {
			$html .= '<p>' . esc_html__( 'No Wordify token is configured.', 'post-domain' ) . '</p>';
		}

		if ( ! $external ) {
			$html .= self::form_open( 'pd_set_wordify_token' );
			$html .= '<p><label for="pd_wordify_token">' . esc_html(
				$binding->has_credential()
					? __( 'Replace the API token', 'post-domain' )
					: __( 'Wordify API token', 'post-domain' )
			) . '</label><br>';
			$html .= '<input type="password" class="regular-text" name="pd_wordify_token" id="pd_wordify_token"'
				. ' autocomplete="off" spellcheck="false">';
			$html .= '</p><p class="description">' . esc_html__(
				'Create a token in the Wordify console with exactly two abilities: Read Sites and Manage Sites. Both are required — reading alone can find your site but cannot attach a domain to it. Do not grant full access. The token is stored encrypted and never displayed again.',
				'post-domain'
			) . '</p>';
			// Saying what the test proves is part of not overstating it: no
			// read-only call reports a token's abilities, and probing for one by
			// performing a live mutation is not something to do behind an
			// operator's back.
			$html .= '<p class="description">' . esc_html__(
				'Test connection checks that the token authenticates and that your teams and sites can be read. It cannot confirm the Manage Sites ability, which is only exercised when a domain is first attached.',
				'post-domain'
			) . '</p>';
			$html .= get_submit_button( __( 'Save token', 'post-domain' ), 'secondary', 'submit', false );
			$html .= '</form>';
		}

		if ( $binding->has_credential() ) {
			$html .= '<div class="pd-actions" style="display:flex;flex-wrap:wrap;gap:.5rem">';
			$html .= self::form_open( 'pd_test_wordify' )
				. '<button type="submit" class="button">' . esc_html__( 'Test connection', 'post-domain' )
				. '</button></form>';

			if ( ! $external ) {
				$html .= self::form_open( 'pd_disconnect_wordify' )
					. '<button type="submit" class="button" onclick="return confirm(' . esc_attr(
						(string) wp_json_encode(
							__( 'Disconnect Wordify? Domains already attached to your Wordify site are left exactly as they are, and no mapping is deleted.', 'post-domain' )
						)
					) . ');">'
					. esc_html__( 'Disconnect', 'post-domain' )
					. '</button></form>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	public static function add_form(): string {
		$readiness = HostingReadiness::evaluate();

		// Withheld rather than shown-and-refused: a domain added here without a
		// working hosting connection would verify, get a certificate, and still
		// serve the host's placeholder page.
		if ( ! $readiness->may_add_domains ) {
			return '';
		}

		$html  = '<h2>' . esc_html__( 'Add a domain', 'post-domain' ) . '</h2>';
		$html .= self::form_open( 'pd_add_mapping' );
		$html .= '<table class="form-table" role="presentation"><tbody>';

		// Domain name first. The content control follows it, because that is the
		// order the operator thinks in and the order the form now reads.
		$html .= '<tr><th scope="row"><label for="pd_host">'
			. esc_html__( 'Domain name', 'post-domain' ) . '</label></th><td>';
		$html .= '<input type="text" class="regular-text" name="pd_host" id="pd_host" '
			. 'placeholder="club.example.org" autocomplete="off" required>';
		$html .= '<p class="description">'
			. esc_html__( 'The hostname visitors will type. Do not include http:// or a path.', 'post-domain' )
			. '</p></td></tr>';

		$html .= '<tr><th scope="row"><label for="pd_post_id">'
			. esc_html__( 'Shows this content', 'post-domain' ) . '</label></th><td>';
		$html .= self::target_control();
		$html .= '</td></tr>';

		$html .= '</tbody></table>';
		$html .= get_submit_button( __( 'Add domain', 'post-domain' ), 'primary', 'submit', false );
		$html .= '</form>';

		return $html;
	}

	/**
	 * One control that both searches and selects.
	 *
	 * A separate search form above the field asked the operator to understand
	 * that finding content and choosing it were two different operations. They
	 * are not. This is a native `<select>` carrying the first page of results,
	 * which submits and validates on its own with no JavaScript at all; the
	 * script upgrades it in place into a combobox that filters as you type,
	 * against the same bounded server-side search.
	 */
	private static function target_control(): string {
		$found = self::target_candidates( '', 1, self::TARGETS_PER_PAGE );

		if ( array() === $found['posts'] ) {
			return '<p id="pd_post_id_help">' . esc_html__(
				'There is no published content to map a domain to yet.',
				'post-domain'
			) . '</p><input type="hidden" name="pd_post_id" value="">';
		}

		$html = '<div class="pd-combobox" data-pd-combobox'
			. ' data-nonce="' . esc_attr( wp_create_nonce( TargetSearch::nonce_action() ) ) . '"'
			. ' data-action="' . esc_attr( TargetSearch::ACTION ) . '"'
			. ' data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';

		$html .= '<select name="pd_post_id" id="pd_post_id" aria-describedby="pd_post_id_help" required>';
		$html .= '<option value="">' . esc_html__( '— Choose —', 'post-domain' ) . '</option>';

		foreach ( $found['posts'] as $post ) {
			$type = get_post_type_object( $post->post_type );

			$html .= sprintf(
				'<option value="%d">%s</option>',
				(int) $post->ID,
				esc_html(
					sprintf(
						/* translators: 1: content title, 2: post type label. */
						__( '%1$s (%2$s)', 'post-domain' ),
						'' === trim( $post->post_title )
							? sprintf( /* translators: %d: post id. */ __( '(no title) #%d', 'post-domain' ), $post->ID )
							: $post->post_title,
						$type?->labels->singular_name ?? $post->post_type
					)
				)
			);
		}

		$html .= '</select>';
		$html .= '</div>';
		$html .= '<p class="description" id="pd_post_id_help">'
			. esc_html__( 'Start typing to search all of your content. Anything beneath the page you choose is served too.', 'post-domain' )
			. '</p>';

		// Said rather than implied. The list below is a real control that submits
		// on its own, but it holds one page: reaching the rest is what needs the
		// script, and claiming otherwise would be the same overstatement this
		// release exists to remove.
		$html .= '<noscript><p class="description">' . esc_html__(
			'Searching your content needs JavaScript. Without it, only the most recent items listed here can be chosen.',
			'post-domain'
		) . '</p></noscript>';

		if ( $found['total'] > count( $found['posts'] ) ) {
			$html .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: total number of eligible items. */
					__( 'Showing the most recent %1$d of %2$d. Type to search the rest; searching needs JavaScript.', 'post-domain' ),
					count( $found['posts'] ),
					$found['total']
				)
			) . '</p>';
		}

		return $html;
	}

	/**
	 * A copy-friendly value with a Copy control in the corner, the way a code
	 * block does it.
	 *
	 * Reused for DNS names and values as well as hostnames: those are the values
	 * an operator retypes into a DNS provider, and a mistyped TXT value is an
	 * afternoon of confusion. The button is a real button, so it is reachable by
	 * keyboard, and it carries the value it copies so the fallback works with no
	 * Clipboard API.
	 */
	public static function copyable( string $value, string $accessible_name ): string {
		return '<span class="pd-copy" data-pd-copy>'
			. '<code class="pd-copy__value" data-pd-copy-value>' . esc_html( $value ) . '</code>'
			. '<button type="button" class="button-link pd-copy__button" data-pd-copy-button'
			. ' aria-label="' . esc_attr( $accessible_name ) . '">'
			. '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>'
			. '<span class="screen-reader-text">' . esc_html( $accessible_name ) . '</span>'
			. '</button>'
			. '<span class="pd-copy__status" role="status" aria-live="polite"></span>'
			. '</span>';
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
				__( 'Actions', 'post-domain' ),
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

			$display = $idna->to_display( $mapping->host );
			$title   = null === $mapping->post_id ? null : get_the_title( $mapping->post_id );

			// The hostname once. It used to appear twice — as a link and again in
			// code styling — which read as two different values.
			$html .= '<tr><td>' . self::copyable(
				$mapping->host,
				sprintf( /* translators: %s: the mapped hostname. */ __( 'Copy %s', 'post-domain' ), $display )
			) . '</td>';

			$html .= '<td>' . esc_html( (string) ( $title ?? __( '(alias)', 'post-domain' ) ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::verification( $mapping->verification_state ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::activation( $mapping->activation_state ) ) . '</td>';
			$html .= '<td>' . esc_html( Labels::ssl( $mapping->ssl_state ) ) . '</td>';

			$html .= '<td><a class="button button-small" href="' . esc_url( 'https://' . $mapping->host . '/' ) . '"'
				. ' target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Test', 'post-domain' )
				. '<span class="screen-reader-text">' . esc_html(
					sprintf( /* translators: %s: the mapped hostname. */ __( 'Open %s in a new tab', 'post-domain' ), $display )
				) . '</span></a> ';
			$html .= '<a class="button button-small" href="' . esc_url( $detail ) . '">'
				. esc_html__( 'Edit', 'post-domain' )
				. '<span class="screen-reader-text">' . esc_html(
					sprintf( /* translators: %s: the mapped hostname. */ __( 'Manage %s', 'post-domain' ), $display )
				) . '</span></a></td>';

			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	public static function detail( Mapping $mapping ): string {
		$idna = new IdnaNormalizer();
		$back = add_query_arg( array( 'page' => SettingsPage::SLUG ), admin_url( 'options-general.php' ) );

		$html  = '<p><a href="' . esc_url( $back ) . '">'
			. esc_html__( '← All domains', 'post-domain' ) . '</a></p>';
		$html .= '<h2>' . esc_html( $idna->to_display( $mapping->host ) ) . '</h2>';
		// Built once here and handed to everything that asks a question about it,
		// so the provider is read a single time per page.
		$plan = self::validation_plan( $mapping );

		$html .= '<p class="description">' . esc_html( Workflow::summary( $mapping, $plan ) ) . '</p>';

		$html .= '<table class="widefat"><tbody>';
		$html .= self::status_row( __( 'Verification', 'post-domain' ), Labels::verification( $mapping->verification_state ), $mapping->verification_state->value );
		$html .= self::status_row( __( 'Serving', 'post-domain' ), Labels::activation( $mapping->activation_state ), $mapping->activation_state->value );
		$html .= self::status_row( __( 'Certificate', 'post-domain' ), Labels::ssl( $mapping->ssl_state ), $mapping->ssl_state->value );
		$html .= '</tbody></table>';

		$html .= self::timings( $mapping );
		$html .= self::steps( $mapping, $plan );
		$html .= self::ownership( $mapping );
		$html .= self::dns_requirements( $mapping, $plan );
		$html .= self::management( $mapping );

		return $html;
	}

	/**
	 * When things last happened and when they happen next.
	 *
	 * Every figure here comes from the state the server enforces or schedules.
	 * Nothing is promised that the persisted schedule does not say: where the
	 * provider owns the timing and there is no deadline to give, that is said
	 * rather than filled in with a plausible number.
	 */
	private static function timings( Mapping $mapping ): string {
		$rows = array();

		if ( null !== $mapping->last_checked_at ) {
			$rows[] = array(
				__( 'Ownership last checked', 'post-domain' ),
				self::when( $mapping->last_checked_at ),
			);
		}

		if ( null !== $mapping->verify_next_attempt_at ) {
			$rows[] = array(
				__( 'Next automatic ownership check', 'post-domain' ),
				self::when( $mapping->verify_next_attempt_at ),
			);
		}

		if ( null !== $mapping->ssl_checked_at ) {
			$rows[] = array(
				__( 'Certificate last checked', 'post-domain' ),
				self::when( $mapping->ssl_checked_at ),
			);
		}

		if ( null !== $mapping->ssl_next_attempt_at ) {
			$rows[] = array(
				__( 'Next automatic certificate check', 'post-domain' ),
				self::when( $mapping->ssl_next_attempt_at ),
			);
		}

		if ( array() === $rows ) {
			return '';
		}

		$html = '<h3>' . esc_html__( 'Timings', 'post-domain' ) . '</h3><table class="widefat"><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row" style="width:18em">' . esc_html( $row[0] ) . '</th><td>'
				. $row[1] . '</td></tr>';
		}

		$html .= '</tbody></table>';

		if ( in_array( $mapping->ssl_state, array( SslState::REQUESTED, SslState::PENDING_VALIDATION ), true ) ) {
			$html .= '<p class="description">' . esc_html__(
				'The certificate provider decides when validation completes, so there is no exact deadline to give. The times above are when this plugin next looks.',
				'post-domain'
			) . '</p>';
		}

		return $html;
	}

	/** A stored UTC timestamp, in the site's timezone, with the raw value kept. */
	private static function when( string $utc ): string {
		$stamp = strtotime( $utc . ' UTC' );

		if ( false === $stamp ) {
			return esc_html( $utc );
		}

		// wp_date() returns false if the timezone cannot be resolved; the stored
		// UTC value is then the honest thing to show.
		$formatted = wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$stamp
		);

		return '<time datetime="' . esc_attr( gmdate( 'c', $stamp ) ) . '">'
			. esc_html( false === $formatted ? $utc : $formatted )
			. '</time>';
	}

	/**
	 * The verification cooldown, from the same representation the server enforces.
	 *
	 * Not WordPress's private `_transient_timeout_*` option, which does not exist
	 * behind an external object cache: reading it there showed no cooldown while
	 * the server was still refusing. `Cooldown` is the one source both sides use.
	 */
	public static function verify_available_at( Mapping $mapping ): ?int {
		return Cooldown::active_until( $mapping->id );
	}

	private static function steps( Mapping $mapping, ?ValidationPlan $plan ): string {
		$html = '<h3>' . esc_html__( 'Setup', 'post-domain' ) . '</h3><ol class="pd-steps">';

		foreach ( Workflow::steps( $mapping, $plan ) as $step ) {
			$html .= '<li class="pd-step pd-step--' . esc_attr( $step->status ) . '">';
			$html .= '<p><strong>' . esc_html( $step->title ) . '</strong> '
				. '<span class="pd-step__status">' . esc_html( $step->status_text() ) . '</span></p>';
			$html .= '<p class="description">' . esc_html( $step->detail ) . '</p>';

			if ( null !== $step->because ) {
				$html .= '<p class="description">' . esc_html( $step->because ) . '</p>';
			}

			$html .= self::step_action( $mapping, $step );
			$html .= '</li>';
		}

		return $html . '</ol>';
	}

	private static function step_action( Mapping $mapping, Step $step ): string {
		if ( 7 === $step->number ) {
			return self::origin_test( $mapping, $step );
		}

		if ( ! $step->is_actionable() || null === $step->action ) {
			return '';
		}

		if ( 'pd_verify' === $step->action ) {
			$available = self::verify_available_at( $mapping );

			if ( null !== $available ) {
				// Disabled because the server will refuse, not to be tidy.
				return '<p><button type="button" class="button" disabled'
					. ' data-pd-countdown="' . esc_attr( (string) $available ) . '">'
					. esc_html__( 'Check verification', 'post-domain' )
					. ' <span class="pd-countdown" role="status" aria-live="polite"></span>'
					. '</button> <span class="description">' . esc_html__(
						'Checks are limited to one a minute.',
						'post-domain'
					) . '</span></p>';
			}
		}

		return '<p>' . self::form_open( $step->action, $mapping->id, $mapping->revision )
			. '<button type="submit" class="button button-primary">'
			. esc_html( (string) $step->label )
			. '</button></form></p>';
	}

	/**
	 * The final step: does the host route the mapped name here.
	 *
	 * The probe runs in the browser on the mapped origin, because only the
	 * browser can produce that Origin — the same boundary the CORS probe already
	 * respects. A page served by the host instead of by this installation runs no
	 * script and reports nothing, which is the honest answer: not confirmed.
	 */
	private static function origin_test( Mapping $mapping, Step $step ): string {
		if ( Step::UPCOMING === $step->status ) {
			return '';
		}

		$open = '<a class="button" href="' . esc_url( 'https://' . $mapping->host . '/' ) . '"'
			. ' target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Open the domain', 'post-domain' ) . '</a>';

		if ( Step::DONE === $step->status ) {
			$at = Workflow::origin_confirmed_at( $mapping );

			return '<p>' . $open . ' <span class="description">' . esc_html(
				sprintf(
					/* translators: %s: a date and time. */
					__( 'Confirmed %s.', 'post-domain' ),
					null === $at ? '' : wp_date(
						(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
						(int) ( false === strtotime( $at . ' UTC' ) ? time() : strtotime( $at . ' UTC' ) )
					)
				)
			) . '</span></p>';
		}

		return '<p>' . $open . '</p>'
			. '<div class="pd-origin-test" data-pd-origin-test'
			. ' data-host="' . esc_attr( $mapping->host ) . '"'
			. ' data-mapping="' . esc_attr( (string) $mapping->id ) . '"'
			// Issued by the server and spendable once, so a proof cannot be
			// replayed and the client cannot choose what it will be asked to sign.
			. ' data-challenge="' . esc_attr( OriginProbe::issue_challenge( $mapping ) ) . '"'
			. ' data-nonce="' . esc_attr( wp_create_nonce( OriginProbe::nonce_action( $mapping->id ) ) ) . '"'
			. ' data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"'
			. ' data-action="' . esc_attr( OriginProbe::ACTION ) . '">'
			. '<p><button type="button" class="button" data-pd-origin-run>'
			. esc_html__( 'Test this domain now', 'post-domain' ) . '</button></p>'
			. '<p class="pd-origin-test__result" role="status" aria-live="polite"></p>'
			. '</div>';
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

	/**
	 * The records to publish, grouped by purpose, each rendered once.
	 *
	 * There is no aggregate table above this. One used to repeat the permanent
	 * ownership TXT that the purpose-grouped output already contains, so the same
	 * record appeared twice and read as two requirements.
	 */
	/**
	 * The plan for this mapping, or null when no provider is bound to read one
	 * from. Called once per page render; everything else is handed the result.
	 */
	private static function validation_plan( Mapping $mapping ): ?ValidationPlan {
		$driver = BoundResource::driver_for( $mapping );

		if ( $driver instanceof DriverUnavailable ) {
			return null;
		}

		$record_name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

		if ( null === $record_name ) {
			return null;
		}

		return $driver->validation_plan(
			SslResourceContext::from_mapping( $mapping, Environment::installation_id(), $record_name, $driver->id() ),
			null
		);
	}

	private static function dns_requirements( Mapping $mapping, ?ValidationPlan $plan ): string {
		$html = '<h3>' . esc_html__( 'DNS records', 'post-domain' ) . '</h3>';

		if ( null === $plan ) {
			$name = Challenge::record_name( $mapping->challenge_label, $mapping->host );

			if ( null === $name ) {
				return $html;
			}

			// Before a provider is bound there is exactly one record to publish,
			// and it is this plugin's own. Rendered here in the same shape the
			// purpose-grouped output uses, so nothing appears twice later.
			return $html . '<h3>' . esc_html__( 'Ownership (post-domain)', 'post-domain' ) . '</h3>'
				. '<p class="pd-permanent">' . esc_html__(
					'Permanent: this record must never be removed while the domain is mapped.',
					'post-domain'
				) . '</p>'
				. '<table class="widefat"><thead><tr><th>' . esc_html__( 'Type', 'post-domain' ) . '</th><th>'
				. esc_html__( 'Name', 'post-domain' ) . '</th><th>' . esc_html__( 'Value', 'post-domain' )
				. '</th></tr></thead><tbody><tr><td>TXT</td><td>'
				. self::copyable( $name, sprintf( /* translators: %s: a DNS record name. */ __( 'Copy the record name %s', 'post-domain' ), $name ) )
				. '</td><td>'
				. self::copyable(
					Challenge::expected_value( $mapping->challenge ),
					__( 'Copy the record value', 'post-domain' )
				)
				. '</td></tr></tbody></table>';
		}

		// DomainDetail escapes as it builds; it must not be run through an
		// allowlist that would strip its tables.
		return $html . DomainDetail::render_plan( $plan, $mapping );
	}

	/**
	 * Everything that is not part of setting the domain up.
	 *
	 * Kept away from the numbered steps on purpose: stopping serving, removing a
	 * certificate and deleting a domain are not stages of onboarding, and putting
	 * them in the same list invites an operator to work through them.
	 */
	private static function management( Mapping $mapping ): string {
		$html = '<h3>' . esc_html__( 'Manage this domain', 'post-domain' ) . '</h3>';

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

		$buttons[] = self::button( 'pd_rotate_challenge', $mapping, __( 'Issue a new verification record', 'post-domain' ), 'secondary' );

		if ( VerificationState::VERIFIED === $mapping->verification_state
			&& ActivationState::ACTIVE === $mapping->activation_state ) {
			$buttons[] = self::button( 'pd_deactivate', $mapping, __( 'Stop serving', 'post-domain' ), 'secondary' );
		}

		if ( null !== $mapping->ssl_ref ) {
			$buttons[] = self::button( 'pd_remove_ssl', $mapping, __( 'Remove the certificate', 'post-domain' ), 'secondary' );
		}

		$html .= '<div class="pd-actions" style="display:flex;flex-wrap:wrap;gap:.5rem">'
			. implode( '', $buttons ) . '</div>';

		$html .= '<h3 class="pd-danger">' . esc_html__( 'Danger zone', 'post-domain' ) . '</h3>';
		$html .= '<p class="description">' . esc_html__(
			'Deleting the domain removes the mapping only. The page or post it shows is not deleted.',
			'post-domain'
		) . '</p>';
		$html .= '<div class="pd-actions" style="display:flex;flex-wrap:wrap;gap:.5rem">'
			. self::button( 'pd_delete_mapping', $mapping, __( 'Delete this domain', 'post-domain' ), 'delete' )
			. '</div>';

		return $html;
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
