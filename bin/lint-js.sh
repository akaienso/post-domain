#!/usr/bin/env bash
# Syntax-checks the plugin's JavaScript.
#
# The admin script is progressive enhancement, so a parse error degrades the
# screen silently rather than failing anything — which is exactly the kind of
# break that reaches a release unnoticed. `node --check` costs nothing and
# refuses to let one through.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
failed=0
checked=0

for file in "${root}"/assets/*.js; do
	[ -e "$file" ] || continue

	checked=$(( checked + 1 ))

	if node --check "$file"; then
		echo "OK   ${file#"${root}/"}"
	else
		failed=$(( failed + 1 ))
	fi
done

echo "${checked} file(s) checked, ${failed} with syntax errors."

exit $(( failed == 0 ? 0 : 1 ))
