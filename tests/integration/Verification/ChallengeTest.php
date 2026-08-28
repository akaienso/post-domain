<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Verification;

use PostDomain\Mapping\ActivationState;
use PostDomain\Mapping\Mapping;
use PostDomain\Mapping\SslState;
use PostDomain\Mapping\VerificationState;
use PostDomain\Verification\Challenge;
use WP_UnitTestCase;

final class ChallengeTest extends WP_UnitTestCase {

	private function mapping( string $host = 'example.test' ): Mapping {
		return new Mapping(
			1, $host, null, 42, 1,
			VerificationState::UNVERIFIED, ActivationState::INACTIVE, SslState::NONE,
			null, str_repeat( 'a', 32 ), '_post-domain-challenge'
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pd_txt_record_label' );
		parent::tear_down();
	}

	public function test_a_token_is_32_lowercase_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Challenge::token() );
	}

	public function test_tokens_do_not_repeat(): void {
		$this->assertNotSame( Challenge::token(), Challenge::token() );
	}

	public function test_the_default_label(): void {
		$this->assertSame( '_post-domain-challenge', Challenge::label_for( $this->mapping() ) );
	}

	public function test_the_record_name_is_label_dot_host(): void {
		$this->assertSame(
			'_post-domain-challenge.example.test',
			Challenge::record_name( '_post-domain-challenge', 'example.test' )
		);
	}

	public function test_the_expected_value_shape(): void {
		$this->assertSame(
			'post-domain-verify=' . str_repeat( 'a', 32 ),
			Challenge::expected_value( str_repeat( 'a', 32 ) )
		);
	}

	public function test_a_valid_custom_label_is_accepted(): void {
		add_filter( 'pd_txt_record_label', static fn(): string => '_acme-owner' );

		$this->assertSame( '_acme-owner', Challenge::label_for( $this->mapping() ) );
	}

	/**
	 * @dataProvider invalid_labels
	 */
	public function test_an_invalid_label_falls_back_to_the_default( string $label ): void {
		add_filter( 'pd_txt_record_label', static fn(): string => $label );

		$this->assertSame( '_post-domain-challenge', Challenge::label_for( $this->mapping() ) );
	}

	/** @return array<string, array{0: string}> */
	public static function invalid_labels(): array {
		return array(
			'contains a dot'   => array( 'a.b' ),
			'empty'            => array( '' ),
			'too long'         => array( str_repeat( 'a', 64 ) ),
			'leading hyphen'   => array( '-bad' ),
			'trailing hyphen'  => array( 'bad-' ),
			'illegal char'     => array( 'bad_label!' ),
		);
	}

	public function test_a_label_is_lowercased(): void {
		add_filter( 'pd_txt_record_label', static fn(): string => '_ACME-Owner' );

		$this->assertSame( '_acme-owner', Challenge::label_for( $this->mapping() ) );
	}

	public function test_the_default_label_leaves_230_bytes_for_the_host(): void {
		$this->assertSame( 230, Challenge::max_host_length( '_post-domain-challenge' ) );
	}

	public function test_a_composed_name_over_253_bytes_is_rejected(): void {
		$host = str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.'
			. str_repeat( 'c', 60 ) . '.' . str_repeat( 'd', 45 ) . '.test';

		$this->assertGreaterThan( 230, strlen( $host ) );
		$this->assertNull( Challenge::record_name( '_post-domain-challenge', $host ) );
	}

	public function test_a_longer_label_shrinks_the_permitted_host(): void {
		$this->assertLessThan(
			Challenge::max_host_length( '_post-domain-challenge' ),
			Challenge::max_host_length( str_repeat( 'x', 40 ) )
		);
	}
}
