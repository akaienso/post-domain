<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use WP_Screen;

/**
 * The in-product operator guide.
 *
 * Repository documentation is not reachable from the admin screen, so the same
 * material lives here twice over: as WordPress-native Help tabs, and as an
 * on-page panel of `<details>` sections that reads correctly with no JavaScript
 * at all.
 *
 * Everything this class returns is already escaped and must be echoed as-is.
 * It must never be passed through `wp_kses_post()`: that allowlist is for post
 * content and strips interactive markup — the exact defect this release already
 * fixed once for the provider selector.
 */
final class Guide {

	/**
	 * Prefix for every Help tab id, so the plugin's tabs cannot collide with a
	 * tab added by core or by another plugin on the same screen.
	 */
	public const TAB_PREFIX = 'post-domain-guide-';

	/**
	 * Adds the plugin's Help tabs to the current admin screen.
	 *
	 * Safe to call when there is no current screen: `get_current_screen()` is
	 * only defined once `admin.php` has loaded, and returns null before the
	 * screen is set up. Both cases return early rather than fatal.
	 */
	public static function register_help_tabs(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		foreach ( self::sections() as $section ) {
			$screen->add_help_tab(
				array(
					'id'      => self::TAB_PREFIX . $section['id'],
					// Core prints the tab title without escaping it.
					'title'   => esc_html( $section['title'] ),
					'content' => self::blocks( $section['blocks'] ),
				)
			);
		}

		$screen->set_help_sidebar( self::sidebar() );
	}

	/**
	 * The on-page "Setup guide" panel.
	 *
	 * Collapsible `<details>` sections, which browsers open and close natively.
	 * No script is loaded and none is required for the content to be readable:
	 * with scripting disabled the sections still expand, and a browser that did
	 * not understand `<details>` at all would simply show every section open.
	 *
	 * @return string Already-escaped markup.
	 */
	public static function render_panel(): string {
		$html  = '<div class="pd-guide card" style="max-width:none">';
		$html .= '<h2>' . esc_html__( 'Setup guide', 'post-domain' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Mapping a domain touches three things that are not this WordPress site: your DNS provider, the certificate service, and your web hosting. Each section below explains one of them in the order you will meet it.', 'post-domain' ) . '</p>';

		foreach ( self::sections() as $section ) {
			$html .= '<details class="pd-guide-section" style="margin:.75rem 0">';
			$html .= '<summary style="cursor:pointer;font-weight:600">' . esc_html( $section['title'] ) . '</summary>';
			$html .= self::blocks( $section['blocks'] );
			$html .= '</details>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param list<array{kind: string, items: list<string>}> $blocks Content blocks.
	 * @return string Already-escaped markup.
	 */
	private static function blocks( array $blocks ): string {
		$html = '';

		foreach ( $blocks as $block ) {
			if ( 'ul' === $block['kind'] || 'ol' === $block['kind'] ) {
				$tag   = 'ul' === $block['kind'] ? 'ul' : 'ol';
				$style = 'ul' === $block['kind'] ? 'list-style:disc' : 'list-style:decimal';
				$html .= '<' . $tag . ' style="margin-left:1.5rem;' . $style . '">';

				foreach ( $block['items'] as $item ) {
					$html .= '<li>' . esc_html( $item ) . '</li>';
				}

				$html .= '</' . $tag . '>';

				continue;
			}

			foreach ( $block['items'] as $item ) {
				$html .= '<p>' . esc_html( $item ) . '</p>';
			}
		}

		return $html;
	}

	/**
	 * @return string Already-escaped markup.
	 */
	private static function sidebar(): string {
		$html  = '<p><strong>' . esc_html__( 'Worth remembering', 'post-domain' ) . '</strong></p>';
		$html .= '<p>' . esc_html__( 'A working certificate does not mean your hosting is serving the domain yet.', 'post-domain' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Leave the ownership record in place until the mapping is deleted.', 'post-domain' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Remove DNS records last, never first.', 'post-domain' ) . '</p>';

		return $html;
	}

	/**
	 * The guide content, in reading order.
	 *
	 * Written for an administrator who has never used a certificate service and
	 * has no reason to learn its vocabulary. Nothing here names an internal
	 * state, a credential, or a constant: those belong in diagnostics, not in an
	 * explanation of what to do next.
	 *
	 * @return list<array{id: string, title: string, blocks: list<array{kind: string, items: list<string>}>}>
	 */
	private static function sections(): array {
		return array(
			array(
				'id'     => 'what-it-does',
				'title'  => __( 'What a mapped domain does', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'A mapped domain shows one page from this WordPress site at a different domain name. A visitor types the mapped domain and sees the page you chose.', 'post-domain' ),
							__( 'The address bar keeps showing the mapped domain the whole time. Nobody is redirected: the visitor is never sent to this site\'s main address, and the page\'s usual path never appears.', 'post-domain' ),
							__( 'The domain is resolved, not forwarded. That difference is the entire point of the feature — if a visitor ends up looking at your main domain, something is wrong, not merely untidy.', 'post-domain' ),
							__( 'Pages filed underneath the page you mapped are reachable underneath the mapped domain in the same way.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'hosting-provider',
				'title'  => __( 'Hosting, certificates and DNS are three different things', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'They are easy to confuse because all three are, in some sense, "where the domain points". They can be three different companies, and this plugin never assumes otherwise.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'Hosting, or origin: whatever finally serves your page. It has to recognise the mapped domain as one of its own names.', 'post-domain' ),
							__( 'The certificate service: it issues the certificate and answers the secure connection.', 'post-domain' ),
							__( 'Authoritative DNS: whoever answers questions about your domain. It can be anywhere, and it does not have to be the same account as anything else.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'If your site is on Wordify, Post Domain can tell Wordify about each mapped domain for you. That needs an API token you create in the Wordify console — the plugin has no credential of its own and never will. Give it exactly two abilities: Read Sites and Manage Sites. Both are needed — reading alone finds your site but cannot attach a domain to it. Do not give it full access. After saving it, choose Test connection and then pick which Wordify site this installation is; Post Domain reads that site back before it binds anything.', 'post-domain' ),
							__( 'Until that connection is made and bound to one site, the Add a domain form is not shown. That is deliberate: a domain added without it would verify, get a certificate, and then show your host\'s placeholder page — a failure that looks like everything worked.', 'post-domain' ),
							__( 'Post Domain never makes a mapped domain your primary domain, and never changes your main site\'s domain, WordPress address or site address.', 'post-domain' ),
							__( 'If the token is later revoked you will not be able to add new domains until you replace it, and every domain already serving keeps serving. Disconnecting removes the plugin\'s permission to act — it detaches no domain and deletes no mapping.', 'post-domain' ),
							__( 'The token is stored encrypted and never shown again; only the fact that one is configured. If you would rather it never touched the database, define PD_WORDIFY_TOKEN in wp-config.php instead, which takes precedence.', 'post-domain' ),
							__( 'Hosted anywhere else? Choose "Manual or another host". No token is needed and no hosting API is contacted.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'hosting-prerequisite',
				'title'  => __( 'Before you begin: your hosting must accept the domain', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'This is the step that most often goes wrong, and it is the one step this plugin cannot perform for you.', 'post-domain' ),
							__( 'The plugin can prove you control the domain, have a certificate issued for it, and turn serving on. When all three are done the screen will say verified, serving, and certificate active, and the domain really will answer securely. None of that makes your web host hand the request to this WordPress site.', 'post-domain' ),
							__( 'Whatever answers requests for your site — the web server, the hosting platform, a reverse proxy, or a CDN in front of it — has to recognise the mapped domain as one of its own names and route it to this same WordPress installation.', 'post-domain' ),
							__( 'It must not rewrite the domain back to your main domain along the way. Many hosts offer an alias mode that simply forwards visitors to the primary domain. Do not use that mode. Forwarding replaces the mapped domain in the address bar and defeats the whole reason for mapping it in the first place.', 'post-domain' ),
							__( 'The name of the setting differs by host: a domain alias, an additional domain, a virtual host entry, or on a CDN an origin host or host header override. If you are not sure, ask your host how to serve one more domain from this same site without redirecting it.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array( __( 'You can recognise this step being missing by any of these:', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'A secure address quietly becomes an insecure one — you asked for HTTPS and got plain HTTP.', 'post-domain' ),
							__( 'The address bar changes to your main domain.', 'post-domain' ),
							__( 'You get the hosting company\'s generic welcome, parked-domain, or "site not found" placeholder page instead of your page.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'moving-parts',
				'title'  => __( 'The parts involved, and which is which', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'Six things have to line up. They are easy to confuse because several of them are "where the domain points", so it is worth being clear about what each one actually is.', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'The WordPress target: the page you pick here. It is what a visitor to the mapped domain ends up seeing.', 'post-domain' ),
							__( 'Authoritative DNS: the service that answers questions about your domain and holds its records. Usually your registrar or a DNS host. This is where you add every record the plugin asks for.', 'post-domain' ),
							__( 'The certificate service, Cloudflare for SaaS: it issues the certificate for the mapped domain and answers the secure connection on the domain\'s behalf. It does not have to be the same company as your DNS, and it is not your hosting.', 'post-domain' ),
							__( 'The CNAME target: the name your domain is pointed at, so requests arrive at the certificate service rather than going straight to your hosting. The plugin tells you the exact value.', 'post-domain' ),
							__( 'The fallback origin: where the certificate service passes a request on to once it has answered it. It is configured once, at the certificate service, and it points at your hosting. It is a different value from the CNAME target and the two are not interchangeable.', 'post-domain' ),
							__( 'The WordPress origin server: this site\'s own hosting, which finally produces the page. This is the part that has to be told about the mapped domain.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'dns-records',
				'title'  => __( 'Which DNS records are permanent and which are temporary', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'You will add up to four records, with four different jobs and four different lifetimes. They are not interchangeable, and removing the wrong one is the most common way to break a working mapping.', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'Permanent — the ownership record. A TXT record at _post-domain-challenge.<your domain>. It must stay for as long as the mapping exists.', 'post-domain' ),
							__( 'Permanent — the routing record. The CNAME that points the domain at the certificate service, or the equivalent arrangement if you are mapping a bare domain. Remove it and the domain stops working immediately.', 'post-domain' ),
							__( 'Temporary — the certificate service\'s own hostname-ownership record. Once the service reports the domain as active, this one may be removed.', 'post-domain' ),
							__( 'Controlled by the certificate service, and possibly wanted again — the certificate validation record. It may go once the certificate has been issued, but a renewal can ask for it again. Being asked for it a second time months later is normal, not a fault.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array( __( 'If you would rather not think about it: leaving all four in place is always safe. Only the ownership and routing records are required to stay.', 'post-domain' ) ),
					),
				),
			),
			array(
				'id'     => 'keep-ownership-record',
				'title'  => __( 'Why the ownership record has to stay', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'Before doing anything privileged with your domain — including deleting its certificate — the plugin checks again that the ownership TXT record is still where it put it. Proving control once, at the start, is not enough; it is re-proved each time it matters.', 'post-domain' ),
							__( 'That check is what stops some other installation, or a copy of this one, from reaching over and interfering with a certificate that is not its own.', 'post-domain' ),
							__( 'So the ownership record is not a one-time formality you can tidy away once the domain verifies. Remove it early and the plugin can no longer prove ownership, which means it will refuse to clean the certificate up. The mapping is then stranded: it can neither finish nor be removed cleanly. Putting the record back lets it recover.', 'post-domain' ),
							__( 'Remove the ownership record only after the mapping has been deleted here.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'two-proofs',
				'title'  => __( 'Two proofs that look alike but are not', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'The certificate service asks for two records that look very similar. They answer different questions, so answering one does not answer the other.', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'Hostname ownership asks: may this domain be attached to this certificate account at all?', 'post-domain' ),
							__( 'Certificate validation asks: may a certificate be issued for this domain, now?', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'Different questions, different records — and not always at the same moment. It is normal to add one, wait, and only then be asked for the other. Neither one being satisfied tells you anything about the other.', 'post-domain' ),
							__( 'Add whichever record the screen is currently asking for, rather than assuming a record you added earlier already covered it.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'waiting',
				'title'  => __( 'Why a certificate can take a while', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'A DNS record is not visible everywhere the moment you save it. It has to spread across the internet first, and the certificate service then has to see it and run its own checks.', 'post-domain' ),
							__( 'A few minutes is normal. Longer is not unusual with a slow DNS provider, and neither is a wait that seems to sit still for a while and then complete all at once.', 'post-domain' ),
							__( 'While that is happening, the plugin waits. It deliberately does not keep asking for a new certificate, because starting over restarts the clock and makes issuance slower rather than faster. A screen that says it is waiting is working.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'countdowns',
				'title'  => __( 'Countdowns and automatic rechecks', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'Asking to check a domain again looks at your DNS afresh. The server allows one such check per domain per minute; ask sooner and it will tell you plainly to wait.', 'post-domain' ),
							__( 'The countdown on screen is a convenience so you know roughly when the button becomes useful again. It is not the rule — the server enforces the wait, and the server is the one that decides. If the countdown and the server ever disagree, believe the server.', 'post-domain' ),
							__( 'Separately, the plugin checks in with the certificate service on its own schedule, in the background. You do not have to sit and watch the page. Close it, come back later, and any progress made in the meantime will be there.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'testing',
				'title'  => __( 'How to test the mapped domain', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'Once the screen reports the domain as verified, serving, and with an active certificate, test it properly:', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ol',
						'items' => array(
							__( 'Open the mapped domain over https:// in a browser window that is not logged in to WordPress. A private or incognito window is enough.', 'post-domain' ),
							__( 'Check that the page shown is the one you mapped.', 'post-domain' ),
							__( 'Check that the address bar still shows the mapped domain, and did not change to your main domain.', 'post-domain' ),
							__( 'Check that the browser still shows the connection as secure, and did not fall back to an insecure one.', 'post-domain' ),
							__( 'Follow one link into a page filed underneath the mapped page, and confirm it also stays on the mapped domain.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'Test from outside your own network if you can, and from a device that has never visited the site. A leftover entry in your computer\'s hosts file, or a cached page, can make a broken setup look perfect.', 'post-domain' ),
							__( 'If any check fails while the plugin still reports everything as fine, read the hosting section above before changing anything here.', 'post-domain' ),
						),
					),
				),
			),
			array(
				'id'     => 'cleanup',
				'title'  => __( 'Removing a mapped domain, in the right order', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'Removal has an order. Doing it out of order strands the mapping and leaves a certificate behind that nothing here can tidy up.', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ol',
						'items' => array(
							__( 'Stop serving the domain, so visitors are no longer being sent to it.', 'post-domain' ),
							__( 'Remove the certificate from here, so the certificate service releases the domain.', 'post-domain' ),
							__( 'Delete the mapping.', 'post-domain' ),
							__( 'Only then remove the DNS records at your DNS provider.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array( __( 'The DNS records go last because the ownership record has to still be present for the plugin to prove it is allowed to remove the certificate. Delete the records first and that proof is gone: the certificate stays at the provider, and nothing on this site can clean it up any more.', 'post-domain' ) ),
					),
				),
			),
			array(
				'id'     => 'copies',
				'title'  => __( 'Copied sites, staging, and restores from backup', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array(
							__( 'Copying a site copies its mappings too. Making a staging copy, cloning to a new host, or restoring an old backup all produce a second installation that believes it owns the same domains and the same certificates as the original.', 'post-domain' ),
							__( 'That second installation must not act on the original\'s certificates. If it did, routine work on a staging copy could delete a live site\'s certificate.', 'post-domain' ),
							__( 'The plugin notices when it is running as a copy rather than as the installation that set the mappings up. When it does, it stands down: it keeps showing you what it knows, but it stops making changes at the certificate service and asks an operator to decide.', 'post-domain' ),
							__( 'Decide deliberately, and only from one installation:', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'On a staging or test copy, release the mappings, so the copy stops claiming domains that belong to the live site.', 'post-domain' ),
							__( 'On a genuine move, where the original installation is gone for good, take the mappings over so the new installation is the one in charge.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array( __( 'Never take them over from two installations at once. If you are unsure which installation you are looking at, check the site address before deciding.', 'post-domain' ) ),
					),
				),
			),
			array(
				'id'     => 'troubleshooting',
				'title'  => __( 'The certificate is active but the domain does not show the right page', 'post-domain' ),
				'blocks' => array(
					array(
						'kind'  => 'p',
						'items' => array( __( 'If the plugin reports verified, serving, and an active certificate, then the domain name, the certificate, and this plugin\'s own records are all in order. What is left is the path between the certificate service and this WordPress site — which is hosting configuration, not something to fix from this screen.', 'post-domain' ) ),
					),
					array(
						'kind'  => 'ul',
						'items' => array(
							__( 'A generic welcome, parked-domain, or "site not found" page appears: the hosting has not been told to serve this domain from this site.', 'post-domain' ),
							__( 'The address bar changes to your main domain: something is forwarding. Usually a host alias set to redirect rather than to serve, or a canonical-URL setting in a CDN, a caching layer, or another plugin.', 'post-domain' ),
							__( 'A secure address becomes an insecure one: the request is arriving somewhere that does not know about the mapped domain.', 'post-domain' ),
							__( 'The browser warns about a certificate for some other domain: the request is not reaching the certificate service at all. Check the routing record at your DNS provider.', 'post-domain' ),
							__( 'The right page loads but links or images point at the main domain: a caching layer, or a hard-coded address in the content, rather than the mapping.', 'post-domain' ),
							__( 'Nothing loads at all and the domain does not resolve: the routing record is missing or has not spread yet.', 'post-domain' ),
						),
					),
					array(
						'kind'  => 'p',
						'items' => array( __( 'In each of those, start with the hosting configuration for the mapped domain, then check this plugin\'s diagnostics for anything it has already flagged. Clear any caching layer in front of the site before re-testing, so you are not looking at an old answer.', 'post-domain' ) ),
					),
				),
			),
		);
	}
}
