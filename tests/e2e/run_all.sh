#!/usr/bin/env bash
# Runs every check, each against a freshly reset database, and prints one line each. Exit status is 0 only if every
# check ran and none failed. Usage: tests/e2e/run_all.sh [name ...]   (names without .py; none means all)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
BASE="${MASHPAD_BASE:-http://127.0.0.1:8081}"           # the checks assume the site is served here: see the README
export MASHPAD_SITE="${MASHPAD_SITE:-/srv/site}" FAKEBREVO_PORT="${FAKEBREVO_PORT:-8099}"
if ! curl -s -o /dev/null -m 3 "http://127.0.0.1:$FAKEBREVO_PORT/v3/account"; then (setsid nohup python3 "$HERE/fakebrevo.py" >/dev/null 2>&1 </dev/null &); sleep 1; fi
ALL=(browser reply_browser thread_browser oauth_browser receipt_browser claim_browser modal_browser notify_browser admin_browser access_browser home_check items_check search_check sched_check polish_check rail_check copy3_check close_check limits_check connect_check cimd_check wake_check viewer_check public_check)
LIST=("${@:-${ALL[@]}}"); bad=0
for t in "${LIST[@]}"; do
  "$HERE/reset.sh" >/dev/null 2>&1 || { echo "$t: could not reset the database (see the README)"; bad=1; continue; }
  out=$(timeout 300 python3 "$HERE/$t.py" "$BASE" 2>&1); sum=$(echo "$out" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
  if [[ -z "$sum" ]]; then echo "$t: DID NOT FINISH"; echo "$out" | tail -3 | sed 's/^/    /'; bad=1
  elif [[ "$sum" == *" 0 failed" ]]; then echo "$t: $sum"
  else echo "$t: $sum"; echo "$out" | grep '^FAIL' | head -5 | cut -c1-200 | sed 's/^/    /'; bad=1; fi
done
exit $bad
