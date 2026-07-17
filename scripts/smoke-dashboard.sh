#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${LARAWA_SMOKE_URL:-http://localhost}"
BASE_URL="${BASE_URL%/}"
ADMIN_EMAIL="${LARAWA_SMOKE_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${LARAWA_SMOKE_ADMIN_PASSWORD:-}"
WORKER_TOKEN="${LARAWA_SMOKE_WORKER_TOKEN:-correct-horse-battery-worker-token}"
SMOKE_DB_CONNECTION="${LARAWA_SMOKE_DB_CONNECTION:-sqlite}"
SMOKE_SQLITE_DATABASE="${LARAWA_SMOKE_SQLITE_DATABASE:-/var/www/html/storage/database/database.sqlite}"
SMOKE_DB_HOST="${LARAWA_SMOKE_DB_HOST:-postgres}"
SMOKE_DB_PORT="${LARAWA_SMOKE_DB_PORT:-5432}"
SMOKE_DB_DATABASE="${LARAWA_SMOKE_DB_DATABASE:-larawa}"
SMOKE_DB_USERNAME="${LARAWA_SMOKE_DB_USERNAME:-larawa}"
SMOKE_DB_PASSWORD="${LARAWA_SMOKE_DB_PASSWORD:-secret}"
SMOKE_DB_SSLMODE="${LARAWA_SMOKE_DB_SSLMODE:-prefer}"
RUN_ID="${LARAWA_SMOKE_RUN_ID:-$(date +%Y%m%d%H%M%S)-$$}"
SESSION_NAME="Smoke Session ${RUN_ID}"
KEY_NAME="Smoke Key ${RUN_ID}"

COOKIE_JAR="$(mktemp)"
TMP_DIR="$(mktemp -d)"
API_KEY=""
API_KEY_ID=""
SESSION_UUID=""

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 127
    fi
}

extract_csrf() {
    ruby -rcgi -e '
        html = STDIN.read
        patterns = [
          /name=["'\'']_token["'\''][^>]*value=["'\'']([^"'\'']+)["'\'']/m,
          /value=["'\'']([^"'\'']+)["'\''][^>]*name=["'\'']_token["'\'']/m,
          /<meta[^>]+name=["'\'']csrf-token["'\''][^>]+content=["'\'']([^"'\'']+)["'\'']/m,
        ]
        match = patterns.lazy.map { |pattern| html.match(pattern) }.find(&:itself)
        abort "Unable to find CSRF token in dashboard HTML" unless match
        puts CGI.unescapeHTML(match[1])
    '
}

extract_plain_text_key() {
    ruby -e '
        html = STDIN.read
        match = html.match(/lwa_[A-Za-z0-9]{48}/)
        abort "Unable to find one-time API key in dashboard response" unless match
        puts match[0]
    '
}

extract_session_uuid() {
    ruby -rjson -e '
        name = ARGV.fetch(0)
        body = JSON.parse(STDIN.read)
        sessions = body.dig("data", "data") || []
        session = sessions.find { |item| item["name"] == name }
        abort "Unable to find smoke session #{name.inspect} in API response" unless session
        puts session.fetch("uuid")
    ' "$1"
}

extract_api_key_id() {
    ruby -rjson -e '
        name = ARGV.fetch(0)
        body = JSON.parse(STDIN.read)
        keys = body.dig("data", "data") || []
        key = keys.find { |item| item["name"] == name }
        abort "Unable to find smoke API key #{name.inspect} in API response" unless key
        puts key.fetch("id")
    ' "$1"
}

cleanup() {
    if [[ -n "${API_KEY}" && -n "${SESSION_UUID}" ]]; then
        curl -fsS -X DELETE \
            -H "Authorization: Bearer ${API_KEY}" \
            "${BASE_URL}/api/v1/sessions/${SESSION_UUID}" >/dev/null 2>&1 || true
    fi

    if [[ -n "${API_KEY}" && -n "${API_KEY_ID}" ]]; then
        curl -fsS -X DELETE \
            -H "Authorization: Bearer ${API_KEY}" \
            "${BASE_URL}/api/v1/api-keys/${API_KEY_ID}" >/dev/null 2>&1 || true
    fi

    rm -rf "$TMP_DIR" "$COOKIE_JAR"
}
trap cleanup EXIT

require_command curl
require_command ruby

echo "==> smoke: first-run setup check"
LOGIN_URL="$(curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -o "${TMP_DIR}/login-or-setup.html" \
    -w "%{url_effective}" \
    "${BASE_URL}/login")"

if grep -q "Initialize LaraWA" "${TMP_DIR}/login-or-setup.html"; then
    ADMIN_EMAIL="${ADMIN_EMAIL:-smoke-admin-${RUN_ID}@example.test}"
    ADMIN_PASSWORD="${ADMIN_PASSWORD:-correct-horse-battery-123}"
    SETUP_CSRF="$(extract_csrf < "${TMP_DIR}/login-or-setup.html")"
    SETUP_STATUS="$(curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
        -o "${TMP_DIR}/setup-complete.html" \
        -w "%{http_code}" \
        --data-urlencode "_token=${SETUP_CSRF}" \
        --data-urlencode "app_url=${BASE_URL}" \
        --data-urlencode "db_connection=${SMOKE_DB_CONNECTION}" \
        --data-urlencode "sqlite_database=${SMOKE_SQLITE_DATABASE}" \
        --data-urlencode "db_host=${SMOKE_DB_HOST}" \
        --data-urlencode "db_port=${SMOKE_DB_PORT}" \
        --data-urlencode "db_database=${SMOKE_DB_DATABASE}" \
        --data-urlencode "db_username=${SMOKE_DB_USERNAME}" \
        --data-urlencode "db_password=${SMOKE_DB_PASSWORD}" \
        --data-urlencode "db_sslmode=${SMOKE_DB_SSLMODE}" \
        --data-urlencode "redis_host=redis" \
        --data-urlencode "redis_port=6379" \
        --data-urlencode "filesystem_disk=local" \
        --data-urlencode "aws_default_region=us-east-1" \
        --data-urlencode "worker_token=${WORKER_TOKEN}" \
        --data-urlencode "workspace_name=Smoke Workspace ${RUN_ID}" \
        --data-urlencode "name=Smoke Admin" \
        --data-urlencode "email=${ADMIN_EMAIL}" \
        --data-urlencode "password=${ADMIN_PASSWORD}" \
        --data-urlencode "password_confirmation=${ADMIN_PASSWORD}" \
        "${BASE_URL}/setup")"
    [[ "$SETUP_STATUS" == "200" ]] || { echo "Initial setup failed with HTTP ${SETUP_STATUS}" >&2; exit 1; }
    grep -q "LaraWA" "${TMP_DIR}/setup-complete.html" || { echo "Setup response did not contain LaraWA branding" >&2; exit 1; }
    : > "$COOKIE_JAR"
elif [[ -z "$ADMIN_EMAIL" || -z "$ADMIN_PASSWORD" ]]; then
    echo "Set LARAWA_SMOKE_ADMIN_EMAIL and LARAWA_SMOKE_ADMIN_PASSWORD for an already-initialized LaraWA stack." >&2
    echo "Last login URL: ${LOGIN_URL}" >&2
    exit 1
fi

echo "==> smoke: dashboard login"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/login" -o "${TMP_DIR}/login.html"
LOGIN_CSRF="$(extract_csrf < "${TMP_DIR}/login.html")"
LOGIN_STATUS="$(curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -o "${TMP_DIR}/dashboard.html" \
    -w "%{http_code}" \
    --data-urlencode "_token=${LOGIN_CSRF}" \
    --data-urlencode "email=${ADMIN_EMAIL}" \
    --data-urlencode "password=${ADMIN_PASSWORD}" \
    "${BASE_URL}/login")"
[[ "$LOGIN_STATUS" == "200" ]] || { echo "Dashboard login failed with HTTP ${LOGIN_STATUS}" >&2; exit 1; }
grep -q "LaraWA" "${TMP_DIR}/dashboard.html" || { echo "Dashboard response did not contain LaraWA branding" >&2; exit 1; }

echo "==> smoke: dashboard API key form"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/dashboard/api-keys" -o "${TMP_DIR}/api-keys.html"
API_KEY_CSRF="$(extract_csrf < "${TMP_DIR}/api-keys.html")"
API_KEY_STATUS="$(curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -o "${TMP_DIR}/api-key-created.html" \
    -w "%{http_code}" \
    --data-urlencode "_token=${API_KEY_CSRF}" \
    --data-urlencode "name=${KEY_NAME}" \
    --data-urlencode "scopes[]=sessions:read" \
    --data-urlencode "scopes[]=sessions:write" \
    --data-urlencode "scopes[]=api-keys:read" \
    --data-urlencode "scopes[]=api-keys:write" \
    "${BASE_URL}/dashboard/api-keys")"
[[ "$API_KEY_STATUS" == "200" ]] || { echo "API key form failed with HTTP ${API_KEY_STATUS}" >&2; exit 1; }
API_KEY="$(extract_plain_text_key < "${TMP_DIR}/api-key-created.html")"

curl -fsS -H "Authorization: Bearer ${API_KEY}" "${BASE_URL}/api/v1/sessions" -o "${TMP_DIR}/sessions-initial.json"
curl -fsS -H "Authorization: Bearer ${API_KEY}" "${BASE_URL}/api/v1/api-keys" -o "${TMP_DIR}/api-keys.json"
API_KEY_ID="$(extract_api_key_id "$KEY_NAME" < "${TMP_DIR}/api-keys.json")"

echo "==> smoke: dashboard session form"
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/dashboard/sessions" -o "${TMP_DIR}/sessions.html"
SESSIONS_CSRF="$(extract_csrf < "${TMP_DIR}/sessions.html")"
SESSION_STATUS="$(curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -D "${TMP_DIR}/session-create.headers" \
    -o "${TMP_DIR}/session-create.html" \
    -w "%{http_code}" \
    --data-urlencode "_token=${SESSIONS_CSRF}" \
    --data-urlencode "name=${SESSION_NAME}" \
    "${BASE_URL}/dashboard/sessions")"
[[ "$SESSION_STATUS" =~ ^30[23]$ ]] || { echo "Session form failed with HTTP ${SESSION_STATUS}" >&2; exit 1; }

for _ in {1..10}; do
    curl -fsS -G \
        -H "Authorization: Bearer ${API_KEY}" \
        --data-urlencode "q=${SESSION_NAME}" \
        --data-urlencode "per_page=5" \
        "${BASE_URL}/api/v1/sessions" -o "${TMP_DIR}/sessions-search.json"

    if SESSION_UUID="$(extract_session_uuid "$SESSION_NAME" < "${TMP_DIR}/sessions-search.json" 2>/dev/null)"; then
        break
    fi

    sleep 1
done

[[ -n "$SESSION_UUID" ]] || { echo "Smoke session was not visible through the API" >&2; exit 1; }

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "q=${SESSION_NAME}" \
    "${BASE_URL}/dashboard/sessions" -o "${TMP_DIR}/sessions-filtered.html"
grep -q "$SESSION_NAME" "${TMP_DIR}/sessions-filtered.html" || { echo "Dashboard sessions page did not show smoke session" >&2; exit 1; }

echo "==> smoke: dashboard log/settings routes"
for path in /dashboard/messages /dashboard/webhooks /dashboard/audit-logs /dashboard/settings; do
    status="$(curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null -w "%{http_code}" "${BASE_URL}${path}")"
    [[ "$status" == "200" ]] || { echo "${path} returned HTTP ${status}" >&2; exit 1; }
done

echo "LaraWA dashboard smoke passed for ${BASE_URL}."
