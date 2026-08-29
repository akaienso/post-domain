#!/usr/bin/env bash
# Proves the readiness state machine in bin/wait-for-mysql.sh.
#
# Each case drives the real script through PD_DB_PROBE_CMD, so the logic under
# test is the committed logic rather than a restatement of it. No database and
# no container are involved: what is being tested is *when* the script declares
# readiness, which is exactly what was wrong.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="${root}/bin/wait-for-mysql.sh"
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

failures=0

report() {
	local ok="$1" name="$2" detail="${3:-}"

	if [ "$ok" = 'yes' ]; then
		printf 'OK    %s%s\n' "$name" "$detail"
	else
		printf 'FAIL  %s%s\n' "$name" "$detail"
		failures=$(( failures + 1 ))
	fi
}

# Runs the script with a scripted probe. The probe reads its own call count from
# a counter file so a case can succeed, then fail, then succeed.
run_with_probe() {
	local probe="$1"
	shift

	# `env` explicitly: assignments only act as a command prefix when parsed
	# literally, not when they arrive through expansion.
	env \
		PD_DB_PROBE_CMD="$probe" \
		PD_DB_CONTAINER='pd-readiness-test-absent' \
		PD_DB_READY_INTERVAL=0 \
		"$@" "$script" 2>&1
}

# --- 1. An internal ping is not enough ------------------------------------
# The old check was `docker exec pd-mysql mysqladmin ping`. Modelled here as a
# probe that succeeds internally while the mapped port still refuses: the script
# must not declare readiness.
echo 'ping_ok' > "${work}/internal-ping"
output="$(run_with_probe '[ -f "'"${work}"'/internal-ping" ] && ! true' PD_DB_READY_TIMEOUT=2)"
status=$?
if [ "$status" -ne 0 ] && ! grep -q 'Database ready' <<< "$output"; then
	report yes 'an internal ping alone never declares readiness'
else
	report no 'an internal ping alone never declares readiness' "  (exit ${status})"
fi

# --- 2. Waits for the externally mapped connection ------------------------
# The probe refuses for the first two attempts, then succeeds forever: the
# script must keep waiting and then succeed.
cat > "${work}/late.sh" <<'PROBE'
#!/usr/bin/env bash
count_file="$1"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
[ "$n" -ge 3 ]
PROBE
chmod +x "${work}/late.sh"
: > "${work}/late.count"
output="$(run_with_probe "${work}/late.sh ${work}/late.count" PD_DB_READY_TIMEOUT=30 PD_DB_READY_CONSECUTIVE=3)"
status=$?
probes="$(cat "${work}/late.count")"
if [ "$status" -eq 0 ] && grep -q 'Database ready' <<< "$output" && [ "$probes" -ge 5 ]; then
	report yes 'waits until the mapped connection and SELECT 1 succeed' "  (${probes} probes)"
else
	report no 'waits until the mapped connection and SELECT 1 succeed' "  (exit ${status}, ${probes} probes)"
fi

# --- 3. Intermittent success resets the counter ---------------------------
# Succeed twice, fail once, then succeed forever. With three consecutive
# successes required, the failure must discard the first two.
cat > "${work}/flap.sh" <<'PROBE'
#!/usr/bin/env bash
count_file="$1"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
# succeed, succeed, FAIL, then succeed from the fourth onwards
[ "$n" -ne 3 ]
PROBE
chmod +x "${work}/flap.sh"
: > "${work}/flap.count"
output="$(run_with_probe "${work}/flap.sh ${work}/flap.count" PD_DB_READY_TIMEOUT=30 PD_DB_READY_CONSECUTIVE=3)"
status=$?
probes="$(cat "${work}/flap.count")"
# Without a reset, probes 1,2 plus 4 would satisfy "3 successes" by probe 4.
# With a reset, readiness cannot come before probe 6.
if [ "$status" -eq 0 ] \
	&& grep -q 'restarting the count' <<< "$output" \
	&& [ "$probes" -ge 6 ]; then
	report yes 'a failure after successes resets the count' "  (ready at probe ${probes}, not 4)"
else
	report no 'a failure after successes resets the count' "  (exit ${status}, ${probes} probes)"
fi

# --- 4. Permanent failure exits nonzero, with diagnostics -----------------
output="$(run_with_probe 'false' PD_DB_READY_TIMEOUT=2)"
status=$?
if [ "$status" -ne 0 ] \
	&& grep -q 'Timed out' <<< "$output" \
	&& grep -q 'docker ps -a' <<< "$output" \
	&& grep -q 'last 50 log lines' <<< "$output" \
	&& ! grep -q 'Database ready' <<< "$output"; then
	report yes 'permanent failure exits nonzero and prints diagnostics'
else
	report no 'permanent failure exits nonzero and prints diagnostics' "  (exit ${status})"
fi

# --- 5. One lucky connection is not readiness, by default -----------------
# Not with an explicit setting: with the shipped default. A default of 1 would
# reintroduce the original defect while every other case above still passed.
cat > "${work}/once.sh" <<'PROBE'
#!/usr/bin/env bash
count_file="$1"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
# Succeeds exactly once, then refuses forever — a port that accepts one
# connection and then drops the handshake.
[ "$n" -eq 1 ]
PROBE
chmod +x "${work}/once.sh"
: > "${work}/once.count"
output="$(run_with_probe "${work}/once.sh ${work}/once.count" PD_DB_READY_TIMEOUT=2)"
status=$?
if [ "$status" -ne 0 ] && ! grep -q 'Database ready' <<< "$output"; then
	report yes 'a single successful connection is not readiness by default'
else
	report no 'a single successful connection is not readiness by default' "  (exit ${status})"
fi

# --- 6. The harness never announces readiness after a failed wait ---------
# integration-env.sh runs under `set -e`, so a failing wait must stop it before
# the final echo.
if grep -q 'bin/wait-for-mysql.sh' "${root}/bin/integration-env.sh" \
	&& grep -q 'set -euo pipefail' "${root}/bin/integration-env.sh" \
	&& ! grep -q 'mysqladmin' "${root}/bin/integration-env.sh"; then
	report yes 'the harness waits on the mapped port and no longer pings internally'
else
	report no 'the harness waits on the mapped port and no longer pings internally'
fi

echo
echo "$(( 6 - failures )) of 6 cases passed."
exit $(( failures == 0 ? 0 : 1 ))
