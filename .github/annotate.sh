#!/usr/bin/env bash
# CI helper: runs one test suite, prints its whole output and turns every FAIL line and PHP error into a GitHub
# annotation — annotations can be read without signing in (check-runs/<job>/annotations), the job log cannot.
#   usage: .github/annotate.sh <suite name> <command> [arguments...]
# Exits with the suite's own exit code.
set -u
name=$1
shift
log=$(mktemp)
"$@" > "$log" 2>&1
rc=$?
cat "$log"
grep -E '^[[:space:]]*FAIL|^(PHP )?(Fatal error|Parse error|Warning|Deprecated|Notice):|Uncaught ' "$log" | head -n 40 \
    | while IFS= read -r line; do
        line=${line//'%'/'%25'}
        printf '::error title=%s::%s\n' "$name" "$line"
    done
if [ "$rc" -ne 0 ] && ! grep -qE '^[[:space:]]*FAIL|Fatal error|Uncaught ' "$log"; then
    printf '::error title=%s::exit code %s; last line: %s\n' "$name" "$rc" "$(tail -n 1 "$log" | sed 's/%/%25/g')"
fi
rm -f "$log"
exit "$rc"
