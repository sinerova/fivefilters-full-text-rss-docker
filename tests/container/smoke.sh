#!/usr/bin/env bash
# Container smoke test. Usage: tests/container/smoke.sh [image]   (default: ftr:test)
# Works with Docker or Podman. Set CONTAINER_CLI=podman (or docker) to choose one.
# Starts the image with the same restrictions as compose.yml and checks that it runs
# unprivileged, writes only to the intended paths, and serves its endpoints.
set -u
IMG="${1:-ftr:test}"
NAME="ftr-smoke-$$"
fail=0
ok()  { echo "PASS: $1"; }
bad() { echo "FAIL: $1"; fail=1; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

if [ -n "${CONTAINER_CLI:-}" ]; then
  CTR="$CONTAINER_CLI"
elif command -v docker >/dev/null 2>&1; then
  CTR=docker
elif command -v podman >/dev/null 2>&1; then
  CTR=podman
else
  echo "Neither docker nor podman was found in PATH." >&2
  exit 2
fi
echo "Using container CLI: $CTR"

cleanup() { "$CTR" rm -f "$NAME" >/dev/null 2>&1; "$CTR" volume rm "$NAME-cache" "$NAME-custom" >/dev/null 2>&1; }
trap cleanup EXIT

"$CTR" run -d --name "$NAME" --read-only --tmpfs /tmp --tmpfs /run/apache2 --tmpfs /var/lock/apache2 \
  --cap-drop ALL --security-opt no-new-privileges -v "$NAME-cache:/var/www/html/cache" -v "$NAME-custom:/var/www/html/site_config/custom" "$IMG" >/dev/null

for i in $(seq 1 30); do
  [ "$("$CTR" inspect -f '{{.State.Health.Status}}' "$NAME")" = healthy ] && break
  sleep 2
done
check "container is healthy" '[ "$("$CTR" inspect -f "{{.State.Health.Status}}" "$NAME")" = healthy ]'

ex() { "$CTR" exec "$NAME" sh -c "$1"; }
http() { ex "curl -s -o /tmp/body -w '%{http_code}' \"$1\"" 2>/dev/null || ex "php -r '
\$c = stream_context_create([\"http\" => [\"ignore_errors\" => true]]);
file_get_contents(\"$1\", false, \$c); preg_match(\"#\\s(\\d{3})\\s#\", \$http_response_header[0], \$m); echo \$m[1];'"; }

check "no process runs as root" '[ -n "$(ex "ps -eo user=")" ] && [ -z "$(ex "ps -eo user=" | grep -w root)" ]'
check "app code is not writable" '! ex "touch /var/www/html/index.php 2>/dev/null"'
check "bundled site rules are not writable" '! ex "touch /var/www/html/site_config/standard/x 2>/dev/null"'
check "cache is writable" 'ex "touch /var/www/html/cache/rss/w"'
check "custom rules dir is writable" 'ex "touch /var/www/html/site_config/custom/w"'
check "root fs is read-only elsewhere" '! ex "touch /etc/x 2>/dev/null"'

# Write footprint: after serving requests, nothing outside the allowed paths changed.
BASE=http://127.0.0.1:8080
check "index returns 200" '[ "$(http $BASE/index.php)" = 200 ]'
check "extract.php answers (no url -> 4xx/200 not 5xx)" 'c=$(http "$BASE/extract.php"); [ "$c" -lt 500 ]'
check "feed rejects loopback with 400" '[ "$(http "$BASE/makefulltextfeed.php?url=http://127.0.0.1/")" = 400 ]'
check "feed rejects metadata IP with 400" '[ "$(http "$BASE/makefulltextfeed.php?url=http://169.254.169.254/")" = 400 ]'
check "feed rejects file:// with 400" '[ "$(http "$BASE/makefulltextfeed.php?url=file:///etc/passwd")" = 400 ]'
http "$BASE/makefulltextfeed.php?url=http://10.0.0.5/" >/dev/null
check "blocked body is generic" '[ "$(ex "cat /tmp/body")" = "This URL cannot be fetched" ]'
check "extract.php rejects loopback with 400" '[ "$(http "$BASE/extract.php?url=http://127.0.0.1/")" = 400 ]'
check "/cache is not served" '[ "$(http $BASE/cache/)" = 403 ]'
check "/site_config is not served" '[ "$(http $BASE/site_config/)" = 403 ]'
check "admin is disabled without a password" 'ex "php -r \"require \\\"/var/www/html/config.php\\\"; exit(\\\$options->admin_credentials[\\\"password\\\"]===\\\"\\\" ? 0 : 1);\""'
check "still healthy after requests" '[ "$("$CTR" inspect -f "{{.State.Health.Status}}" "$NAME")" = healthy ]'

changed=$("$CTR" diff "$NAME" | grep -vE ' /(tmp|run|var/lock|var/www/html/cache|var/www/html/site_config/custom)(/|$)' | grep -vE ' /(run|var/lock|var/run)' || true)
check "no filesystem changes outside intended paths" '[ -z "$changed" ]'
[ -n "$changed" ] && echo "$changed"

exit $fail
