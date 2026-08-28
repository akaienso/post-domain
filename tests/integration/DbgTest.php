<?php
declare( strict_types = 1 );
namespace PostDomain\Tests\Integration;
use PostDomain\Support\Schema;
use WP_UnitTestCase;
final class DbgTest extends WP_UnitTestCase {
	public function test_dbg(): void {
		global $wpdb;
		$t = Schema::domains_table();
		fwrite(STDERR, "before install: ".var_export($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)),true)."\n");
		Schema::install();
		fwrite(STDERR, "after install: ".var_export($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)),true)."\n");
		$wpdb->query("DROP TABLE IF EXISTS {$t}");
		fwrite(STDERR, "err: ".$wpdb->last_error."\n");
		fwrite(STDERR, "after drop: ".var_export($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)),true)."\n");
		$this->assertTrue(true);
	}
}
