#!/usr/bin/env bash
#
# Redirect-path benchmark.
#
# Measures the three cases that matter separately, because averaging them
# together hides the thing you actually want to know:
#
#   warm  — slug is in the Redis cache (the case that must be fast)
#   cold  — cache flushed, every request falls through to MySQL
#   miss  — unknown slug, served by the negative cache
#
set -euo pipefail

HOST="${HOST:-http://127.0.0.1:8000}"
SLUG="${SLUG:?set SLUG to a real short slug}"
REQUESTS="${REQUESTS:-20000}"
CONCURRENCY="${CONCURRENCY:-100}"

command -v ab >/dev/null || { echo "apache2-utils (ab) is required"; exit 1; }

run() {
    local label="$1" path="$2"

    echo
    echo "=== $label : $path ==="

    # -k reuses connections; without it the numbers measure TCP setup, not the app.
    ab -n "$REQUESTS" -c "$CONCURRENCY" -k -r "$HOST$path" 2>/dev/null \
        | grep -E 'Requests per second|Time per request|50%|95%|99%|Non-2xx'
}

echo "Warming the cache for /$SLUG"
curl -s -o /dev/null "$HOST/$SLUG"

run "warm (redis hit)" "/$SLUG"

echo
read -r -p "Flush the redirect cache and measure cold? [y/N] " reply
if [[ "$reply" == "y" ]]; then
    redis-cli -n "${REDIS_LINKS_DB:-1}" FLUSHDB
    run "cold (mysql fallback)" "/$SLUG"
fi

run "miss (negative cache)" "/zzzzzzz"

echo
echo "Click buffer depth after the run:"
curl -s "$HOST/api/health" | grep -o '"click_buffer_depth":[0-9]*' || true
