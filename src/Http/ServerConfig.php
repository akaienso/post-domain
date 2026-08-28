<?php
declare( strict_types = 1 );

namespace PostDomain\Http;

/**
 * Generates the rules the plugin cannot apply. PHP never runs for a static file,
 * so the unknown-host rule and the CORS header for assets must live in the web
 * server or CDN.
 */
final class ServerConfig {

	/**
	 * @param string[] $allowed_hosts
	 * @return array<string, string>
	 */
	public static function snippets( array $allowed_hosts ): array {
		$primary = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$allowed = array_merge( array( $primary ), $allowed_hosts );
		$list    = implode( ' ', array_map( 'strval', $allowed ) );

		return array(
			'note'       => 'PHP never runs for a static file, so these rules cannot be applied by the plugin. '
				. 'Without them an unknown host can still fetch assets, and a webfont on a mapped domain '
				. 'will fail silently in fallback fonts.',
			'nginx'      => "map \$host \$pd_known_host {\n"
				. "    default 0;\n"
				. implode(
					"\n",
					array_map( static fn( string $h ): string => "    {$h} 1;", $allowed )
				)
				. "\n}\n\nserver {\n"
				. "    if (\$pd_known_host = 0) { return 421; }\n"
				. "}\n",
			'apache'     => '<If "%{HTTP_HOST} !~ /^(' . implode( '|', array_map( 'preg_quote', $allowed ) ) . ")\$/\">\n"
				. "    Redirect 421 /\n"
				. "</If>\n",
			'cloudflare' => "Transform Rule expression:\n"
				. 'not http.host in { ' . $list . " }\n"
				. "Action: respond with status 421\n",
		);
	}
}
