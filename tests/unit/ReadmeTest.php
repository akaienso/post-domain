<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase {

	private function readme(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );
	}

	/**
	 * @dataProvider required_topics
	 */
	public function test_the_readme_covers_every_required_topic( string $needle ): void {
		$this->assertStringContainsString( $needle, $this->readme() );
	}

	/** @return array<string, array{0: string}> */
	public static function required_topics(): array {
		return array(
			'minimums'             => array( 'WordPress 6.4' ),
			'exact hosts'          => array( 'Wildcard' ),
			'permanent record'     => array( 'must never be removed' ),
			'filter reference'     => array( 'pd_mapping_is_active' ),
			'init 99'              => array( 'init` priority 10' ),
			'early url limit'      => array( 'not rebased' ),
			'driver interface'     => array( 'SslDriver' ),
			'ownership provenance' => array( 'ssl_ownership_origin' ),
			'lease phases'         => array( 'RECOVERING' ),
			'authorization'        => array( 'fresh' ),
			'create ambiguity'     => array( 'adopt' ),
			'clone detection'      => array( 'clone' ),
			'resolver trust'       => array( 'pd_dns_resolver' ),
			'driver selection'     => array( 'Certificate provider' ),
			'no silent no-op'      => array( 'pd_ssl_not_configured' ),
			'event atomicity'      => array( 'pd_schema_engine' ),
			'provider binding'     => array( 'environment' ),
			'resource binding'     => array( 'does not move because the plugin was repointed' ),
			'dcv default'          => array( 'txt' ),
			'apex entitlement'     => array( 'BYOIP' ),
			'dns neutrality'       => array( 'authoritative DNS' ),
			'multisite'            => array( 'multisite' ),
			'421 default'          => array( '421' ),
			'cors boundary'        => array( 'Access-Control-Allow-Origin' ),
			'auth consequences'    => array( 'COOKIE_DOMAIN' ),
			'uninstall order'      => array( 'before uninstalling' ),
		);
	}

	public function test_the_readme_states_cloudflare_dns_is_not_required(): void {
		$readme = $this->readme();

		$this->assertMatchesRegularExpression(
			'/Cloudflare[^.]*recommended[^.]*not required/i',
			$readme
		);
	}

	public function test_the_readme_does_not_promise_universal_url_interception(): void {
		$this->assertStringContainsString( 'not interceptable', $this->readme() );
	}
}
