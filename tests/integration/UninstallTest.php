<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration;

use PostDomain\Support\Schema;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_drops_our_tables_and_options_and_nothing_else(): void {
		global $wpdb;

		Schema::install();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Survives uninstall' ) );
		update_post_meta( $post_id, 'unrelated_meta', 'keep me' );
		update_option( 'unrelated_option', 'keep me', false );
		update_option( 'pd_settings', array( 'a' => 1 ), false );
		update_option( 'pd_installation_id', 'abc', false );

		define( 'WP_UNINSTALL_PLUGIN', 'post-domain/post-domain.php' );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::domains_table() ) )
		);
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::events_table() ) )
		);
		$this->assertFalse( get_option( 'pd_settings' ) );
		$this->assertFalse( get_option( 'pd_installation_id' ) );
		$this->assertFalse( get_option( 'pd_schema_version' ) );

		$this->assertSame( 'Survives uninstall', get_post( $post_id )?->post_title );
		$this->assertSame( 'keep me', get_post_meta( $post_id, 'unrelated_meta', true ) );
		$this->assertSame( 'keep me', get_option( 'unrelated_option' ) );
	}
}
