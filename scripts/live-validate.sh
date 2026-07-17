#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${LARAWA_BASE_URL:-http://localhost}"
API_KEY="${LARAWA_API_KEY:-}"
SESSION_UUID="${LARAWA_SESSION_UUID:-}"
RECIPIENT="${LARAWA_RECIPIENT:-}"
GROUP_ID="${LARAWA_GROUP_ID:-}"
WEBHOOK_ID="${LARAWA_WEBHOOK_ID:-}"
IMAGE_PATH="${LARAWA_IMAGE_PATH:-}"
RESTART_WORKER="${LARAWA_RESTART_WORKER:-0}"
RUN_OPTIONAL_SENDS="${LARAWA_RUN_OPTIONAL_SENDS:-0}"
WAIT_WEBHOOK_SECONDS="${LARAWA_WAIT_WEBHOOK_SECONDS:-30}"
WAIT_INCOMING_SECONDS="${LARAWA_WAIT_INCOMING_SECONDS:-0}"
INCOMING_MARKER="${LARAWA_INCOMING_MARKER:-}"

usage() {
    cat <<'USAGE'
Usage:
  LARAWA_API_KEY=lwa_... LARAWA_SESSION_UUID=... LARAWA_RECIPIENT='+1 202-555-0100' scripts/live-validate.sh

Environment:
  LARAWA_BASE_URL              LaraWA URL. Defaults to http://localhost.
  LARAWA_API_KEY               API key with sessions:read, messages:send, and messages:read.
                                Add webhooks:read and webhooks:write when LARAWA_WEBHOOK_ID is set.
  LARAWA_SESSION_UUID          Ready WhatsApp session UUID.
  LARAWA_RECIPIENT             International phone number or @c.us id for 1:1 sends, e.g. +1 202-555-0100.
  LARAWA_GROUP_ID              Optional group id ending in @g.us for group send validation.
  LARAWA_WEBHOOK_ID            Optional webhook id for signed webhook.test delivery validation.
  LARAWA_IMAGE_PATH            Optional image path. If omitted, a tiny PNG is generated in /tmp.
  LARAWA_RESTART_WORKER=1      Optionally restart wa-worker and confirm the session returns ready.
  LARAWA_RUN_OPTIONAL_SENDS=1  Also send document, audio, and reaction requests.
  LARAWA_WAIT_WEBHOOK_SECONDS  Seconds to wait for webhook.test delivery. Defaults to 30.
  LARAWA_WAIT_INCOMING_SECONDS Seconds to wait for a real incoming WhatsApp message. Defaults to 0.
  LARAWA_INCOMING_MARKER       Required incoming text marker. Defaults to a generated marker when waiting.
USAGE
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing required command: $1" >&2
        exit 127
    fi
}

require_env() {
    if [[ -z "${!1:-}" ]]; then
        echo "Missing required environment variable: $1" >&2
        usage >&2
        exit 2
    fi
}

require_command curl
require_command ruby

require_env LARAWA_API_KEY
require_env LARAWA_SESSION_UUID
require_env LARAWA_RECIPIENT

if [[ ! "$WAIT_WEBHOOK_SECONDS" =~ ^[0-9]+$ || "$WAIT_WEBHOOK_SECONDS" -lt 1 ]]; then
    echo "LARAWA_WAIT_WEBHOOK_SECONDS must be a positive integer." >&2
    exit 2
fi

if [[ ! "$WAIT_INCOMING_SECONDS" =~ ^[0-9]+$ ]]; then
    echo "LARAWA_WAIT_INCOMING_SECONDS must be zero or a positive integer." >&2
    exit 2
fi

if [[ "$RESTART_WORKER" == "1" ]]; then
    require_command docker
fi

tmp_body="$(mktemp)"
cleanup() {
    rm -f "$tmp_body" "${generated_image:-}"
}
trap cleanup EXIT

step() {
    echo
    echo "==> $*"
}

json_field() {
    local expression="$1"
    ruby -rjson -e "body = JSON.parse(STDIN.read); value = ${expression}; puts(value.nil? ? '' : value)"
}

request() {
    local method="$1"
    local path="$2"
    local expected="$3"
    local body="${4:-}"
    local url="${BASE_URL%/}${path}"
    local code

    if [[ -n "$body" ]]; then
        code="$(curl -sS -o "$tmp_body" -w '%{http_code}' -X "$method" "$url" \
            -H "Authorization: Bearer $API_KEY" \
            -H 'Content-Type: application/json' \
            -d "$body")"
    else
        code="$(curl -sS -o "$tmp_body" -w '%{http_code}' -X "$method" "$url" \
            -H "Authorization: Bearer $API_KEY")"
    fi

    if [[ "$code" != "$expected" ]]; then
        echo "Expected HTTP $expected from $method $path, got $code" >&2
        cat "$tmp_body" >&2
        echo >&2
        exit 1
    fi

    cat "$tmp_body"
}

request_public() {
    local path="$1"
    local expected="$2"
    local code

    code="$(curl -sS -o "$tmp_body" -w '%{http_code}' "${BASE_URL%/}${path}")"

    if [[ "$code" != "$expected" ]]; then
        echo "Expected HTTP $expected from GET $path, got $code" >&2
        cat "$tmp_body" >&2
        echo >&2
        exit 1
    fi

    cat "$tmp_body"
}

base64_file() {
    ruby -e 'print [File.binread(ARGV.fetch(0))].pack("m0")' "$1"
}

json_escape() {
    ruby -rjson -e 'print ARGV.fetch(0).to_json' "$1"
}

urlencode() {
    ruby -rcgi -e 'print CGI.escape(ARGV.fetch(0))' "$1"
}

delivery_status_by_id() {
    ruby -rjson -e '
        id = ARGV.fetch(0).to_i
        body = JSON.parse(STDIN.read)
        deliveries = body.dig("data", "data") || []
        delivery = deliveries.find { |item| item["id"].to_i == id }
        puts(delivery ? delivery["status"] : "")
    ' "$1"
}

latest_delivery_id() {
    ruby -rjson -e '
        body = JSON.parse(STDIN.read)
        deliveries = body.dig("data", "data") || []
        delivery = deliveries.first
        puts(delivery ? delivery["id"] : "0")
    '
}

message_count() {
    ruby -rjson -e '
        body = JSON.parse(STDIN.read)
        messages = body.dig("data", "data") || []
        puts messages.length
    '
}

tiny_png() {
    generated_image="$(mktemp -t larawa-live-image.XXXXXX.png)"
    ruby -e 'File.binwrite(ARGV.fetch(0), ["iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII="].pack("m0"))' "$generated_image"
    echo "$generated_image"
}

assert_ready_session() {
    local response status

    response="$(request GET "/api/v1/sessions/${SESSION_UUID}" 200)"
    status="$(printf '%s' "$response" | json_field 'body.dig("data", "status")')"

    if [[ "$status" != "ready" ]]; then
        echo "Session ${SESSION_UUID} is not ready; current status is '${status}'." >&2
        exit 1
    fi

    echo "Session ${SESSION_UUID} is ready."
}

wait_for_delivery() {
    local delivery_id="$1"
    local event="$2"
    local seconds="$3"
    local deadline status response

    deadline=$((SECONDS + seconds))

    while (( SECONDS <= deadline )); do
        response="$(request GET "/api/v1/webhook-deliveries?event=${event}&per_page=20" 200)"
        status="$(printf '%s' "$response" | delivery_status_by_id "$delivery_id")"

        if [[ "$status" == "delivered" ]]; then
            echo "Webhook delivery ${delivery_id} delivered."
            return 0
        fi

        if [[ "$status" == "failed" || "$status" == "exhausted" || "$status" == "skipped" ]]; then
            echo "Webhook delivery ${delivery_id} ended with status ${status}." >&2
            printf '%s\n' "$response" >&2
            exit 1
        fi

        sleep 2
    done

    echo "Webhook delivery ${delivery_id} did not reach delivered within ${seconds}s; last status was '${status:-not found}'." >&2
    exit 1
}

wait_for_new_delivery_after() {
    local webhook_id="$1"
    local event="$2"
    local previous_id="$3"
    local seconds="$4"
    local deadline response id status

    deadline=$((SECONDS + seconds))

    while (( SECONDS <= deadline )); do
        response="$(request GET "/api/v1/webhook-deliveries?webhook_id=${webhook_id}&event=${event}&per_page=10" 200)"
        id="$(printf '%s' "$response" | latest_delivery_id)"

        if [[ "$id" =~ ^[0-9]+$ && "$id" -gt "$previous_id" ]]; then
            status="$(printf '%s' "$response" | delivery_status_by_id "$id")"

            if [[ "$status" == "delivered" ]]; then
                echo "Webhook ${event} delivery ${id} delivered."
                return 0
            fi

            if [[ "$status" == "failed" || "$status" == "exhausted" || "$status" == "skipped" ]]; then
                echo "Webhook ${event} delivery ${id} ended with status ${status}." >&2
                printf '%s\n' "$response" >&2
                exit 1
            fi
        fi

        sleep 2
    done

    echo "No delivered ${event} webhook appeared within ${seconds}s." >&2
    exit 1
}

timestamp="$(date +%Y%m%d%H%M%S)"
if [[ "$WAIT_INCOMING_SECONDS" != "0" && -z "$INCOMING_MARKER" ]]; then
    INCOMING_MARKER="LaraWA live validation reply ${timestamp}"
fi

step "Health check"
health="$(request_public /healthz 200)"
printf '%s\n' "$health" | ruby -rjson -e 'body = JSON.parse(STDIN.read); abort("healthz is not ok") unless body["ok"] == true && body["database"] == "ok"; puts "Database connection: #{body["connection"]}"'

step "Session readiness"
assert_ready_session

step "API key permission preflight"
request GET "/api/v1/messages?per_page=1" 200 >/dev/null
if [[ -n "$WEBHOOK_ID" ]]; then
    request GET "/api/v1/webhook-deliveries?webhook_id=${WEBHOOK_ID}&per_page=1" 200 >/dev/null
fi
echo "Required read scopes are available."

if [[ "$RESTART_WORKER" == "1" ]]; then
    step "Worker restart persistence"
    docker compose restart wa-worker >/dev/null
    sleep 20
    docker compose exec -T app php artisan larawa:sessions:sync >/dev/null
    assert_ready_session
fi

step "Send text message"
text_payload="$(ruby -rjson -e 'puts({to: ARGV[0], text: "LaraWA live validation text #{ARGV[1]}", idempotency_key: "live-text-#{ARGV[1]}"}.to_json)' "$RECIPIENT" "$timestamp")"
text_response="$(request POST "/api/v1/sessions/${SESSION_UUID}/messages/text" 202 "$text_payload")"
text_id="$(printf '%s' "$text_response" | json_field 'body.dig("data", "wa_message_id")')"
text_row_id="$(printf '%s' "$text_response" | json_field 'body.dig("data", "id")')"
echo "Text message accepted: row=${text_row_id} wa_message_id=${text_id}"

step "Send image message"
image_file="$IMAGE_PATH"
if [[ -z "$image_file" ]]; then
    image_file="$(tiny_png)"
fi

if [[ ! -f "$image_file" ]]; then
    echo "Image file not found: $image_file" >&2
    exit 1
fi

image_base64="$(base64_file "$image_file")"
image_payload="$(ruby -rjson -e 'puts({to: ARGV[0], media_base64: ARGV[1], mime_type: "image/png", filename: "larawa-live-validation.png", caption: "LaraWA live validation image", idempotency_key: "live-image-#{ARGV[2]}"}.to_json)' "$RECIPIENT" "$image_base64" "$timestamp")"
image_response="$(request POST "/api/v1/sessions/${SESSION_UUID}/messages/image" 202 "$image_payload")"
image_row_id="$(printf '%s' "$image_response" | json_field 'body.dig("data", "id")')"
echo "Image message accepted: row=${image_row_id}"

step "Download stored image media"
curl -fsS -o /dev/null "${BASE_URL%/}/api/v1/messages/${image_row_id}/media" -H "Authorization: Bearer $API_KEY"
echo "Stored image media downloaded successfully."

if [[ -n "$GROUP_ID" ]]; then
    step "Send group text message"
    group_payload="$(ruby -rjson -e 'puts({to: ARGV[0], text: "LaraWA live validation group #{ARGV[1]}", idempotency_key: "live-group-#{ARGV[1]}"}.to_json)' "$GROUP_ID" "$timestamp")"
    request POST "/api/v1/sessions/${SESSION_UUID}/messages/text" 202 "$group_payload" >/dev/null
    echo "Group message accepted for ${GROUP_ID}."
fi

if [[ -n "$WEBHOOK_ID" ]]; then
    step "Queue webhook test delivery"
    webhook_response="$(request POST "/api/v1/webhooks/${WEBHOOK_ID}/test" 202)"
    delivery_id="$(printf '%s' "$webhook_response" | json_field 'body.dig("data", "id")')"
    echo "Webhook test delivery queued: delivery=${delivery_id}"
    wait_for_delivery "$delivery_id" webhook.test "$WAIT_WEBHOOK_SECONDS"
fi

if [[ "$RUN_OPTIONAL_SENDS" == "1" ]]; then
    step "Send optional document and audio messages"
    document_base64="$(ruby -e 'print ["LaraWA live validation document"].pack("m0")')"
    document_payload="$(ruby -rjson -e 'puts({to: ARGV[0], media_base64: ARGV[1], mime_type: "application/pdf", filename: "larawa-live-validation.pdf", caption: "LaraWA live validation document", idempotency_key: "live-document-#{ARGV[2]}"}.to_json)' "$RECIPIENT" "$document_base64" "$timestamp")"
    request POST "/api/v1/sessions/${SESSION_UUID}/messages/document" 202 "$document_payload" >/dev/null

    audio_base64="$(ruby -e 'print ["OggS LaraWA live validation audio"].pack("m0")')"
    audio_payload="$(ruby -rjson -e 'puts({to: ARGV[0], media_base64: ARGV[1], mime_type: "audio/ogg", filename: "larawa-live-validation.ogg", as_voice: true, idempotency_key: "live-audio-#{ARGV[2]}"}.to_json)' "$RECIPIENT" "$audio_base64" "$timestamp")"
    request POST "/api/v1/sessions/${SESSION_UUID}/messages/audio" 202 "$audio_payload" >/dev/null

    if [[ -n "$text_id" ]]; then
        reaction_payload="$(ruby -rjson -e 'puts({message_id: ARGV[0], reaction: "👍", idempotency_key: "live-reaction-#{ARGV[1]}"}.to_json)' "$text_id" "$timestamp")"
        request POST "/api/v1/sessions/${SESSION_UUID}/messages/reaction" 202 "$reaction_payload" >/dev/null
    fi

    echo "Optional document, audio, and reaction requests were accepted."
fi

step "Message log check"
request GET "/api/v1/messages?q=live%20validation&per_page=10" 200 >/dev/null
echo "Message log API is reachable for live validation rows."

if [[ "$WAIT_INCOMING_SECONDS" != "0" ]]; then
    step "Incoming WhatsApp message check"
    marker_query="$(urlencode "$INCOMING_MARKER")"
    before_delivery_id="0"

    if [[ -n "$WEBHOOK_ID" ]]; then
        before_response="$(request GET "/api/v1/webhook-deliveries?webhook_id=${WEBHOOK_ID}&event=message.received&per_page=1" 200)"
        before_delivery_id="$(printf '%s' "$before_response" | latest_delivery_id)"
    fi

    echo "Send a WhatsApp message to the paired account containing exactly this marker:"
    echo "$INCOMING_MARKER"

    deadline=$((SECONDS + WAIT_INCOMING_SECONDS))
    incoming_seen=0

    while (( SECONDS <= deadline )); do
        incoming_response="$(request GET "/api/v1/messages?session=${SESSION_UUID}&direction=incoming&q=${marker_query}&per_page=5" 200)"
        count="$(printf '%s' "$incoming_response" | message_count)"

        if [[ "$count" -gt 0 ]]; then
            incoming_seen=1
            break
        fi

        sleep 3
    done

    if [[ "$incoming_seen" != "1" ]]; then
        echo "No incoming message containing '${INCOMING_MARKER}' appeared within ${WAIT_INCOMING_SECONDS}s." >&2
        exit 1
    fi

    echo "Incoming marker message appeared in the LaraWA message log."

    if [[ -n "$WEBHOOK_ID" ]]; then
        wait_for_new_delivery_after "$WEBHOOK_ID" message.received "$before_delivery_id" "$WAIT_WEBHOOK_SECONDS"
    fi
fi

echo
echo "LaraWA live validation API checks passed."
echo "Confirm in WhatsApp and the dashboard that outgoing messages arrived and operational logs match this run."
