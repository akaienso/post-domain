#!/usr/bin/env bash
# Waits until the database is reachable over the exact path WordPress will use.
#
# The previous readiness check was `docker exec pd-mysql mysqladmin ping`, which
# proves only that mysqld answers from inside the container. WordPress connects
# over the published port from the host, and MySQL 8 publishes that port before
# it will complete a handshake on it: the container is up, the mapping exists,
# and a connection is accepted and then dropped mid-greeting. That is what
# "Error while reading greeting packet" and "MySQL server has gone away" are.
#
# So the probe here is the real connection — 127.0.0.1:33306, the real
# credentials, the real database, and a real query — and one success is not
# enough. During the unstable window a connection can succeed and the next one
# fail, so readiness means N consecutive successes, and any failure resets the
# count to zero.
set -euo pipefail

container="${PD_DB_CONTAINER:-pd-mysql}"
host="${PD_DB_HOST:-127.0.0.1}"
port="${PD_DB_PORT:-33306}"
user="${PD_DB_USER:-root}"
password="${PD_DB_PASSWORD:-pd}"
database="${PD_DB_NAME:-wordpress_test}"

consecutive_required="${PD_DB_READY_CONSECUTIVE:-3}"
probe_interval="${PD_DB_READY_INTERVAL:-1}"
timeout_seconds="${PD_DB_READY_TIMEOUT:-180}"

# One probe: connect the way WordPress will, run a trivial query, read it back.
# A test seam, not a shortcut: PD_DB_PROBE_CMD lets the harness tests drive this
# state machine without a database. Nothing sets it in CI or in normal use.
probe_database() {
	if [ -n "${PD_DB_PROBE_CMD:-}" ]; then
		eval "${PD_DB_PROBE_CMD}"

		return $?
	fi

	php -r '
		mysqli_report( MYSQLI_REPORT_OFF );

		$connection = @mysqli_init();

		if ( false === $connection ) {
			exit( 1 );
		}

		@mysqli_options( $connection, MYSQLI_OPT_CONNECT_TIMEOUT, 3 );
		@mysqli_options( $connection, MYSQLI_OPT_READ_TIMEOUT, 5 );

		$connected = @mysqli_real_connect(
			$connection,
			$argv[1],
			$argv[2],
			$argv[3],
			$argv[4],
			(int) $argv[5]
		);

		if ( false === $connected ) {
			exit( 1 );
		}

		$result = @mysqli_query( $connection, "SELECT 1" );

		if ( false === $result ) {
			exit( 1 );
		}

		$row = mysqli_fetch_row( $result );

		@mysqli_close( $connection );

		exit( is_array( $row ) && 1 === (int) $row[0] ? 0 : 1 );
	' -- "$host" "$user" "$password" "$database" "$port" >/dev/null 2>&1
}

container_state() {
	docker inspect -f '{{.State.Status}}' "$container" 2>/dev/null || echo 'absent'
}

container_restarting() {
	docker inspect -f '{{.State.Restarting}}' "$container" 2>/dev/null || echo 'false'
}

diagnostics() {
	echo "--- docker ps -a (${container}) ---" >&2
	docker ps -a --filter "name=${container}" >&2 2>/dev/null || true

	echo "--- state ---" >&2
	docker inspect -f 'status={{.State.Status}} running={{.State.Running}} restarting={{.State.Restarting}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} error={{.State.Error}}' \
		"$container" >&2 2>/dev/null || echo "(container ${container} could not be inspected)" >&2

	echo "--- health ---" >&2
	docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}(no healthcheck){{end}}' \
		"$container" >&2 2>/dev/null || true

	echo "--- port bindings ---" >&2
	docker inspect -f '{{json .NetworkSettings.Ports}}' "$container" >&2 2>/dev/null || true

	echo "--- last 50 log lines ---" >&2
	docker logs --tail 50 "$container" >&2 2>&1 || echo "(no logs available)" >&2
}

deadline=$(( SECONDS + timeout_seconds ))
consecutive=0
attempts=0

while [ "$SECONDS" -lt "$deadline" ]; do
	state="$(container_state)"

	# A container that has exited, or is stuck restarting, will never become
	# ready. Waiting out the full timeout to say so helps nobody.
	if [ "$state" = 'exited' ] || [ "$state" = 'dead' ]; then
		echo "The ${container} container is ${state}; it will not become ready." >&2
		diagnostics

		exit 1
	fi

	if [ "$(container_restarting)" = 'true' ]; then
		echo "The ${container} container is restarting repeatedly." >&2
		diagnostics

		exit 1
	fi

	attempts=$(( attempts + 1 ))

	if probe_database; then
		consecutive=$(( consecutive + 1 ))

		if [ "$consecutive" -ge "$consecutive_required" ]; then
			echo "Database ready: ${consecutive_required} consecutive connections to ${host}:${port} after ${attempts} probes."

			exit 0
		fi
	else
		# Reset, not decrement. A connection that fails after two successes means
		# the server is still coming up, and the two successes proved nothing
		# about the state that follows them.
		if [ "$consecutive" -gt 0 ]; then
			echo "Connection to ${host}:${port} failed after ${consecutive} consecutive successes; restarting the count." >&2
		fi

		consecutive=0
	fi

	sleep "$probe_interval"
done

echo "Timed out after ${timeout_seconds}s waiting for a stable connection to ${host}:${port} (${attempts} probes, ${consecutive} consecutive successes at the end)." >&2
diagnostics

exit 1
