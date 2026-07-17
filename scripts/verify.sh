#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_UP="${LARAWA_VERIFY_COMPOSE_UP:-0}"
POSTGRES_UP="${LARAWA_VERIFY_POSTGRES_UP:-0}"
POSTGRES_PROJECT="${LARAWA_VERIFY_POSTGRES_PROJECT:-larawa-postgres-verify}"
POSTGRES_PORT="${LARAWA_VERIFY_POSTGRES_PORT:-18080}"

usage() {
    cat <<'USAGE'
Usage: scripts/verify.sh [--with-compose-up] [--with-postgres-up]

Runs the LaraWA release verification checks:
  - Vite production build
  - Laravel feature/unit tests, excluding plugin integration tests
  - Composer and npm production dependency audits
  - Pint formatting check
  - WhatsApp worker syntax check
  - OpenAPI YAML parse
  - Docker Compose config validation for SQLite and PostgreSQL profile
  - PostgreSQL env profile activation
  - Environment example/sample alias consistency
  - Dashboard/API smoke when a Docker stack is started

Options:
  --with-compose-up    Also build and start the default SQLite Docker stack, then check /healthz.
  --with-postgres-up   Build and start an isolated PostgreSQL profile stack, then check /healthz.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --with-compose-up)
            COMPOSE_UP=1
            shift
            ;;
        --with-postgres-up)
            POSTGRES_UP=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

run() {
    echo
    echo "==> $*"
    "$@"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 127
    fi
}

wait_for_compose_health() {
    local services=("$@")
    local output=""

    for _ in {1..60}; do
        if output="$(docker compose ps --format json | ruby -rjson -e '
            expected = ARGV
            rows = STDIN.each_line.filter_map { |line| JSON.parse(line) rescue nil }
            by_service = rows.to_h { |row| [row["Service"], row] }

            bad = expected.filter_map do |service|
              row = by_service[service]

              if row.nil?
                "#{service}: missing"
              elsif row["State"] != "running"
                "#{service}: #{row["State"]}"
              elsif row.key?("Health") && !row["Health"].to_s.empty? && row["Health"] != "healthy"
                "#{service}: #{row["Health"]}"
              end
            end

            puts bad
            exit(bad.empty? ? 0 : 1)
        ' "${services[@]}")"; then
            return 0
        fi

        sleep 2
    done

    echo "Compose services did not become healthy:" >&2
    echo "$output" >&2
    docker compose ps >&2
    return 1
}

wait_for_postgres_compose_health() {
    local services=(app nginx queue redis scheduler wa-worker postgres)
    local output=""

    for _ in {1..60}; do
        if output="$(APP_PORT="$POSTGRES_PORT" docker compose --env-file .env.postgres.example -p "$POSTGRES_PROJECT" ps --format json | ruby -rjson -e '
            expected = ARGV
            rows = STDIN.each_line.filter_map { |line| JSON.parse(line) rescue nil }
            by_service = rows.to_h { |row| [row["Service"], row] }

            bad = expected.filter_map do |service|
              row = by_service[service]

              if row.nil?
                "#{service}: missing"
              elsif row["State"] != "running"
                "#{service}: #{row["State"]}"
              elsif row.key?("Health") && !row["Health"].to_s.empty? && row["Health"] != "healthy"
                "#{service}: #{row["Health"]}"
              end
            end

            puts bad
            exit(bad.empty? ? 0 : 1)
        ' "${services[@]}")"; then
            return 0
        fi

        sleep 2
    done

    echo "PostgreSQL profile services did not become healthy:" >&2
    echo "$output" >&2
    APP_PORT="$POSTGRES_PORT" docker compose --env-file .env.postgres.example -p "$POSTGRES_PROJECT" ps >&2
    return 1
}

assert_health_connection() {
    local expected_connection="$1"
    local url="${2:-http://localhost/healthz}"

    curl -fsS "$url" | ruby -rjson -e '
        expected = ARGV.fetch(0)
        body = JSON.parse(STDIN.read)

        unless body["ok"] == true && body["database"] == "ok" && body["connection"] == expected
          warn "Unexpected health response: #{body.inspect}"
          exit 1
        end
    ' "$expected_connection"
}

assert_openapi_route_coverage() {
    php artisan route:list --json --path=api/v1 | ruby -rjson -ryaml -e '
        routes = JSON.parse(STDIN.read).flat_map do |route|
          path = route.fetch("uri").sub(%r{\Aapi/v1}, "")
          path = "/" if path.empty?

          route.fetch("method").split("|").reject { |method| method == "HEAD" }.map do |method|
            [method.downcase, path]
          end
        end.sort

        openapi = YAML.load_file("docs/openapi.yaml")
        docs = openapi.fetch("paths").flat_map do |path, operations|
          operations.keys
            .map(&:to_s)
            .select { |method| %w[get post put patch delete].include?(method) }
            .map { |method| [method, path] }
        end.sort

        missing = routes - docs
        extra = docs - routes

        if missing.any? || extra.any?
          warn "OpenAPI route coverage mismatch."
          warn "Missing from docs:"
          missing.each { |method, path| warn "  #{method.upcase} #{path}" }
          warn "Documented but not routed:"
          extra.each { |method, path| warn "  #{method.upcase} #{path}" }
          exit 1
        end
    '
}

assert_openapi_auth_schemes() {
    ruby -ryaml -e '
        openapi = YAML.load_file("docs/openapi.yaml")
        schemes = openapi.dig("components", "securitySchemes") || {}
        security = openapi.fetch("security", [])
        errors = []

        bearer = schemes["ApiKeyAuth"]
        header = schemes["ApiKeyHeader"]

        errors << "ApiKeyAuth bearer scheme is missing" unless bearer&.fetch("type", nil) == "http" && bearer&.fetch("scheme", nil) == "bearer"
        errors << "ApiKeyHeader X-API-Key scheme is missing" unless header&.fetch("type", nil) == "apiKey" && header&.fetch("in", nil) == "header" && header&.fetch("name", nil) == "X-API-Key"
        errors << "Top-level security must allow bearer API keys" unless security.any? { |entry| entry.key?("ApiKeyAuth") }
        errors << "Top-level security must allow X-API-Key API keys" unless security.any? { |entry| entry.key?("ApiKeyHeader") }

        if errors.any?
          warn "OpenAPI auth scheme check failed:"
          warn errors.join("\n")
          exit 1
        end
    '
}

assert_postgres_env_profile() {
    docker compose --env-file .env.postgres.example config --services | ruby -e '
        services = STDIN.each_line.map(&:strip)

        unless services.include?("postgres")
          warn ".env.postgres.example must enable the postgres Compose profile via COMPOSE_PROFILES=postgres"
          exit 1
        end
    '
}

assert_env_aliases_match_examples() {
    if [ -f .env.sample ]; then
        diff -u .env.example .env.sample
    fi

    if [ -f .env.postgres.sample ]; then
        diff -u .env.postgres.example .env.postgres.sample
    fi

    if [ -f .env.redis-postgres.sample ]; then
        diff -u .env.redis-postgres.example .env.redis-postgres.sample
    fi
}

assert_security_headers() {
    local url="${1:-http://localhost/login}"

    curl -fsSI "$url" | ruby -e '
        headers = STDIN.each_line.each_with_object({}) do |line, memo|
          name, value = line.split(":", 2)
          next unless value

          memo[name.downcase] = value.strip
        end

        expected = {
          "x-frame-options" => "SAMEORIGIN",
          "x-content-type-options" => "nosniff",
          "referrer-policy" => "strict-origin-when-cross-origin",
          "permissions-policy" => "camera=(), microphone=(), geolocation=(), payment=()",
          "cross-origin-opener-policy" => "same-origin",
          "x-permitted-cross-domain-policies" => "none",
        }

        missing = expected.filter_map do |name, value|
          actual = headers[name]
          "#{name}=#{actual.inspect}" unless actual == value
        end

        csp = headers["content-security-policy"].to_s
        required_csp = ["default-src '\''self'\''", "object-src '\''none'\''", "frame-ancestors '\''self'\''", "script-src '\''self'\''", "style-src '\''self'\''"]
        missing.concat(required_csp.filter_map { |directive| "content-security-policy missing #{directive}" unless csp.include?(directive) })

        if missing.any?
          warn "Security header check failed:"
          warn missing.join("\n")
          exit 1
        end
    '
}

assert_docs_unavailable() {
    local base_url="${1:-http://localhost}"
    local path

    for path in /docs /docs/openapi.yaml; do
        local status
        status="$(curl -sS -o /dev/null -w '%{http_code}' "${base_url}${path}")"

        if [[ "$status" != "404" ]]; then
            warn "Expected ${base_url}${path} to be unavailable in production, got HTTP ${status}"
            exit 1
        fi
    done
}

run_php_tests_without_plugins() {
    local test_files=()
    local test_file

    while IFS= read -r test_file; do
        test_files+=("$test_file")
    done < <(find tests -name '*Test.php' ! -name '*PluginTest.php' | sort)

    run php artisan test "${test_files[@]}"
}

require_command php
require_command node
require_command npm
require_command ruby
require_command docker

if [[ "$COMPOSE_UP" == "1" || "$POSTGRES_UP" == "1" ]]; then
    require_command curl
fi

run npm run build
run_php_tests_without_plugins
run composer audit --format=plain
run npm audit --omit=dev
run npm --prefix worker audit --omit=dev
run ./vendor/bin/pint --test
run bash -n scripts/live-validate.sh
run node --check worker/src/server.js
run npm --prefix worker test
run ruby -ryaml -e 'YAML.load_file("docs/openapi.yaml")'
run assert_openapi_route_coverage
run assert_openapi_auth_schemes
run docker compose config --quiet
run docker compose --profile postgres config --quiet
run assert_postgres_env_profile
run assert_env_aliases_match_examples

if [[ "$COMPOSE_UP" == "1" ]]; then
    run docker compose --env-file .env.example up -d --build
    run wait_for_compose_health app nginx queue redis scheduler wa-worker
    run assert_health_connection sqlite
    run assert_security_headers
    run assert_docs_unavailable
    run env LARAWA_SMOKE_URL="http://localhost" scripts/smoke-dashboard.sh
    run docker compose ps
fi

if [[ "$POSTGRES_UP" == "1" ]]; then
    run env APP_PORT="$POSTGRES_PORT" docker compose --env-file .env.postgres.example -p "$POSTGRES_PROJECT" up -d --build
    run wait_for_postgres_compose_health
    run assert_health_connection pgsql "http://localhost:${POSTGRES_PORT}/healthz"
    run assert_security_headers "http://localhost:${POSTGRES_PORT}/login"
    run assert_docs_unavailable "http://localhost:${POSTGRES_PORT}"
    run env LARAWA_SMOKE_URL="http://localhost:${POSTGRES_PORT}" LARAWA_SMOKE_DB_CONNECTION=pgsql scripts/smoke-dashboard.sh
    run env APP_PORT="$POSTGRES_PORT" docker compose --env-file .env.postgres.example -p "$POSTGRES_PROJECT" ps
fi

echo
echo "LaraWA verification passed."
