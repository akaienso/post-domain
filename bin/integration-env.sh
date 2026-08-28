#!/usr/bin/env bash
# Brings up the integration harness: an isolated MySQL container plus a
# WordPress checkout and the WordPress test suite under tmp/.
#
# wp-env is the documented harness (Plan 01, Task 3) and .wp-env.json is kept
# for CI, but its tests-cli image fails to build in this environment. This runs
# the same WordPress test suite natively against a disposable container, which
# is what `composer test:integration` uses locally.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_version="${PD_WP_VERSION:-7.1}"
tag="${PD_WP_TAG:-7.1.0}"

docker inspect pd-mysql >/dev/null 2>&1 || docker run -d --name pd-mysql \
	-e MYSQL_ROOT_PASSWORD=pd -e MYSQL_DATABASE=wordpress_test -p 33306:3306 mysql:8.4 >/dev/null

docker start pd-mysql >/dev/null 2>&1 || true

for _ in $(seq 1 60); do
	docker exec pd-mysql mysqladmin -uroot -ppd ping >/dev/null 2>&1 && break
	sleep 2
done

mkdir -p "${root}/tmp"
cd "${root}/tmp"

if [ ! -d wp ]; then
	curl -sSL "https://wordpress.org/wordpress-${wp_version}.tar.gz" -o wp.tgz
	tar xzf wp.tgz && mv wordpress wp && rm wp.tgz
fi

if [ ! -d wp-tests ]; then
	curl -sSL -o dev.tgz "https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/${tag}"
	tar xzf dev.tgz
	mkdir -p wp-tests
	cp -R "wordpress-develop-${tag}/tests/phpunit/includes" wp-tests/
	cp -R "wordpress-develop-${tag}/tests/phpunit/data" wp-tests/
	rm -rf dev.tgz "wordpress-develop-${tag}"
fi

cat > wp-tests/wp-tests-config.php <<'CONFIG'
<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/wp/' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'post-domain integration' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEFAULT_THEME', 'default' );

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'pd' );
define( 'DB_HOST', '127.0.0.1:33306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
CONFIG

echo "integration harness ready: WP_TESTS_DIR=${root}/tmp/wp-tests"
