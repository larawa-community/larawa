# LaraWA Setup Guide

## Production Checklist

1. Copy `.env.example` to `.env`.
2. Set a strong `APP_KEY` with `php artisan key:generate --show` or let the entrypoint generate a persistent one on first boot.
3. Run `docker compose up -d --build`.
4. Open the dashboard and complete the one-time installer.
5. Configure the public URL, timezone, optional Cloudflare Flexible SSL proxy mode, WA worker URL/callback/token, webhook limits, database, optional Redis, storage, first workspace, and site administrator.
6. Let the installer test the configured services, write the allowlisted settings to `.env`, force `APP_ENV=production` and `APP_DEBUG=false`, run migrations, and set `LARAWA_INSTALLED=true`.
7. Lock down `.env` file permissions after setup.
8. Create scoped API keys for applications instead of using broad `*` keys.
9. Configure webhook endpoints over HTTPS and verify HMAC signatures.
10. Run `docker compose exec app php artisan larawa:doctor` and resolve critical findings before exposing the service.

For PostgreSQL with database-backed cache, queues, and sessions, copy `.env.postgres.example` to `.env` and start with `docker compose up -d --build`. For PostgreSQL plus Redis-backed cache, queues, and sessions, copy `.env.redis-postgres.example` instead. Both examples set `COMPOSE_PROFILES=postgres` and provide matching Compose service defaults; the installer still writes the final Laravel settings.

In Docker, installer-managed environment settings are persisted at `storage/app/larawa/.env` inside the `larawa_storage` volume and shared with Laravel containers and the worker. Local non-Docker installs use the project `.env` unless `LARAWA_ENV_PATH` is set. The installer only writes a fixed allowlist of environment keys, rejects values containing new lines, generates `APP_KEY` if needed, and finalizes production-safe `APP_ENV`/`APP_DEBUG` values. If the Cloudflare Flexible SSL option is selected, it writes `APP_FORCE_HTTPS=true` and `TRUSTED_PROXIES=*`.

Docker builds are designed to be reproducible from source. The root and worker build contexts exclude local `.env` files, runtime storage, logs, SQLite databases, local dependency folders, test fixtures, and stale Vite output so secrets and workstation artifacts are not baked into images.

## Pair Your First WhatsApp Session

1. Login to the dashboard.
2. Open `Sessions`.
3. Create a session.
4. Open the session and scan the QR code from WhatsApp > Linked devices.
5. Wait for the status to become `ready`.

The worker stores browser auth material in the `larawa_worker_sessions` Docker volume, so sessions survive container restarts.

Use `Reconnect` to start a stopped or failed session and request a fresh QR when needed. Use `Stop` or `POST /api/v1/sessions/{session}/disconnect` to stop the worker client while preserving WhatsApp auth for a later reconnect. Use `Logout` or `POST /api/v1/sessions/{session}/logout` to remove stored WhatsApp auth while keeping the LaraWA session row for a fresh QR. Deleting a session stops it in the worker, removes it from the worker restore registry, and removes stored WhatsApp browser auth by default. API callers can pass `"destroy_worker_session": false` only when intentionally preserving LocalAuth files outside LaraWA.

If the worker is unreachable or rejects a Stop, Logout, or Delete request, LaraWA keeps the current session state, records the worker failure in session metadata, and writes an audit log entry instead of pretending the destructive operation succeeded.

The scheduler container runs `php artisan larawa:sessions:sync` every minute to refresh stored session status, QR state, phone metadata, and worker availability from the live worker. The dashboard session list and `GET /api/v1/sessions` support `status`, `q`, and `per_page` filters for multi-session operations.

## Health Checks

LaraWA exposes `GET /healthz` for HTTP readiness and `php artisan larawa:health` for PHP container readiness. The HTTP response includes the active database connection so operators can confirm SQLite or PostgreSQL at runtime. Docker Compose uses these checks for `app`, `queue`, `scheduler`, and `nginx`, plus `/health` for the WhatsApp worker and `redis-cli ping` for Redis.

Use `php artisan larawa:doctor` for production-readiness diagnostics. It checks APP_KEY, debug mode, log verbosity, public URL, initial setup completion, worker token, database, queue, cache, session driver, secure dashboard session cookies, storage, S3-compatible endpoint shape, rate limiting, outbound URL policies, and webhook timeout configuration. The dashboard Settings page shows the same diagnostics. Add `--strict` when deployment scripts should fail on warnings as well as critical issues.

Dashboard login attempts are rate limited by email address and client IP. The limiter blocks the login form after repeated failures and clears the counter after a successful login.

## Dashboard Roles

The first administrator created during setup is a `site_admin`. LaraWA stores site admin access as a membership on the initial system admin workspace, so any workspace with a `site_admin` membership is protected and cannot be suspended or deleted. The initial user account is also protected and cannot be disabled or deleted. Site admins can create, edit, suspend, soft-delete, and inspect other workspaces; create, edit, disable, reset, and delete other users; assign workspace admins; and view global sessions, API keys, audit logs, and system settings.

Workspace admins manage only their assigned workspace. They can invite users, change workspace roles, remove workspace users, create/delete WhatsApp sessions, manage API keys, manage webhooks, send messages through scoped keys, view audit logs, and update workspace settings. They cannot access platform settings, worker administration, or other workspaces.

Workspace users can view their assigned workspace, sessions, message logs, and webhook delivery logs. They cannot create or delete users, manage API keys, manage webhooks, change settings, or delete sessions. Dashboard menus are role-aware, and every protected route also enforces authorization server-side with `403` responses.

Disabling a non-initial user sets `disabled_at` and blocks login/dashboard access. Suspending a non-system workspace sets `suspended_at`, blocks non-site-admin dashboard access, and rejects that workspace's API keys with `403` without deleting workspace data.

The bundled nginx entrypoint emits browser hardening headers for the dashboard and non-production API docs: content security policy, same-origin framing, MIME sniffing protection, referrer policy, permissions policy, cross-origin opener isolation, and cross-domain policy denial. API docs return `404` when `APP_ENV=production`. LaraWA should still sit behind HTTPS in production; configure HSTS at the TLS terminator or reverse proxy that owns certificates.

The worker starts after nginx is healthy so restored WhatsApp sessions can deliver startup callbacks to Laravel instead of racing the HTTP entrypoint. `WA_WORKER_INTERNAL_TOKEN` must be a non-empty high-entropy shared secret and must match in the Laravel and worker containers; blank tokens are rejected on both internal APIs.

The scheduler runs `php artisan larawa:sessions:sync` every minute. This reconciles dashboard/API session state from the worker even when an earlier callback was missed, and stores worker availability errors in session metadata for troubleshooting.

If `APP_KEY` is blank, `docker/laravel/entrypoint.sh` creates one in `storage/app/larawa/app.key` inside the persistent `larawa_storage` volume and exports it before Laravel config is cached. The same generated key is reused by `app`, `queue`, and `scheduler` containers on later starts. For controlled production deployments, prefer setting `APP_KEY` explicitly in `.env` and backing it up with the database and media volumes.

When LaraWA is served over HTTPS, set `SESSION_SECURE_COOKIE=true` so browser session cookies are only sent on secure requests. Keep it `false` only for plain HTTP local development or isolated test networks.

## Docker Services

- `app`: PHP-FPM Laravel application.
- `nginx`: public HTTP entrypoint.
- `queue`: webhook delivery and async work.
- `scheduler`: Laravel scheduler.
- `wa-worker`: `whatsapp-web.js` browser worker.
- `redis`: optional Redis cache/queue/session backend.
- `postgres`: optional database profile.

Compose healthchecks cover Laravel/database readiness, nginx `/healthz`, the worker `/health` endpoint, Redis `PING`, and PostgreSQL readiness when the profile is enabled. The default nginx entrypoint also emits browser hardening headers including CSP, frame protection, MIME sniffing protection, referrer policy, permissions policy, and cross-origin opener isolation. Terminate HTTPS in front of nginx for production and add HSTS at that TLS boundary.

## SQLite Storage And Backup

SQLite data is stored at `storage/database/database.sqlite` inside the `larawa_storage` Docker volume. Back it up with:

```bash
docker compose exec app php artisan db:show
docker run --rm -v larawa_storage:/data -v "$PWD/backups:/backup" alpine sh -c 'cp /data/database/database.sqlite /backup/database-$(date +%Y%m%d%H%M%S).sqlite'
```

Older LaraWA builds stored SQLite in the `larawa_database` volume. The app service mounts that legacy volume read-only at `/legacy-database` and copies `database.sqlite` into `larawa_storage` on first boot if the new database file does not exist.

## PostgreSQL And Redis

Start from the PostgreSQL example environment when you want PostgreSQL for the database and Laravel database-backed cache, queues, and sessions:

```bash
cp .env.postgres.example .env
docker compose up -d --build
```

Or set these values in `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=larawa
DB_USERNAME=larawa
DB_PASSWORD=secret
```

Start with the PostgreSQL profile:

```bash
COMPOSE_PROFILES=postgres docker compose up -d --build
```

Redis is included by default. To use it for queues, cache, and sessions with PostgreSQL, start from the Redis plus PostgreSQL example:

```bash
cp .env.redis-postgres.example .env
docker compose up -d --build
```

Or set these values in `.env`:

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
```

## Worker Storage

WhatsApp browser sessions live in `larawa_worker_sessions`. Inbound media and API-submitted `media_base64` files are stored by Laravel on the configured `FILESYSTEM_DISK`, so back up `larawa_storage` for local storage or your S3-compatible bucket when preserving media logs. Decoded base64 media is capped by `LARAWA_MEDIA_BASE64_MAX_BYTES`, defaulting to 25 MiB, before either inbound worker media or API-submitted media is stored.

Container shutdown is designed to preserve WhatsApp logins. On `SIGTERM` or `SIGINT`, the worker reports unhealthy, stops accepting new HTTP requests, closes active `whatsapp-web.js` browser clients without calling logout, and leaves the restore registry plus LocalAuth files in place. `WA_WORKER_SHUTDOWN_TIMEOUT_MS` controls the internal shutdown deadline, and Compose gives the worker a 30 second grace period before forcefully stopping it.

Session `Stop`/`POST /api/v1/sessions/{session}/disconnect` unregisters the active worker client from automatic restoration but preserves LocalAuth data and the LaraWA session row, so `Reconnect` can start it later without a new QR scan when WhatsApp still accepts the stored auth. Session `Logout`/`POST /api/v1/sessions/{session}/logout` removes LocalAuth data, clears phone/QR metadata, and keeps the session row for a fresh QR. Session deletion unregisters the session from worker restart restoration. By default it also logs out and removes the session's LocalAuth directory. The REST API accepts `destroy_worker_session=false` for advanced migrations where you need to preserve the auth files deliberately while deleting the LaraWA row.

## API Key Scopes

- `*`: all API actions.
- `sessions:read`: list and inspect sessions.
- `sessions:write`: create/delete sessions.
- `messages:read`: list message logs.
- `messages:send`: send text, media, reactions, and bulk messages.
- `webhooks:read`: list webhooks.
- `webhooks:write`: create, update, delete, test, retry, and rotate webhook secrets.
- `api-keys:read`: list API keys through the REST API.
- `api-keys:write`: create, update, rotate, and revoke API keys through the REST API. Non-wildcard keys can only grant or update scopes they already have.

REST API clients may authenticate with `Authorization: Bearer lwa_...` or `X-API-Key: lwa_...`; both forms use the same scoped, hashed API key records.

API key IP allow lists accept comma-separated exact IPv4/IPv6 addresses and CIDR ranges, such as `203.0.113.10`, `198.51.100.0/24`, or `2001:db8::/32`. Invalid entries are rejected when a key is created or updated. API key expiration timestamps must be in the future when provided; use a blank value or `null` when a key should not expire. Rotate an API key from the dashboard or with `POST /api/v1/api-keys/{apiKey}/rotate`; the previous token stops working immediately and the replacement plain-text key is shown once.

## Message Idempotency

Text, media, reaction, and bulk send requests accept `idempotency_key`. Use a unique key per intended outbound message. If a client retries the same request with the same key and payload, LaraWA returns the original message record without calling the WhatsApp worker again after a successful send. If the previous attempt failed before the worker could send, such as worker unreachable or session not ready, LaraWA reuses the same message row and tries the worker again. Ambiguous worker errors are not retried automatically because the worker might already have sent the WhatsApp message. If the same key is reused with different payload data, single-message endpoints return `409 Conflict`; bulk sends reject duplicate keys and existing key/payload conflicts with `422 Unprocessable Entity` before any message in the batch is sent.

Media clients can call the generic `POST /api/v1/sessions/{session}/messages/media` endpoint with `type=image|video|document|audio`, or use typed endpoints at `/messages/image`, `/messages/video`, `/messages/document`, and `/messages/audio`. Audio accepts `as_voice=true` to request a WhatsApp voice-note send where whatsapp-web.js supports it. Media payloads require valid base64 or a valid URL, plus a MIME type that matches image, video, or audio sends; document sends accept any valid MIME type. Decoded base64 media is limited by `LARAWA_MEDIA_BASE64_MAX_BYTES`, and the default Docker PHP/nginx request envelope is 64 MiB. Bulk sends accept up to 500 mixed text and media items and validate the whole batch before any worker send is attempted.

`media_url` is public-only by default: LaraWA rejects loopback, private, reserved, and unresolvable hosts before the worker fetches the media, and the worker repeats the same check for every redirect target before downloading bytes. This reduces SSRF risk for scoped API keys. Set `MEDIA_URL_ALLOW_PRIVATE=true` only in trusted deployments where API callers intentionally reference private media URLs; the Settings page and `php artisan larawa:doctor --strict` warn when that mode is enabled.

## Stored Media

Inbound media and API-submitted `media_base64` payloads are stored on the configured filesystem disk and removed from message payload JSON. Decoded base64 media is capped before storage, so oversized API payloads are rejected without calling the WhatsApp worker and oversized inbound worker media is rejected before message or webhook side effects. Dashboard message views show download links when a message has stored media. API clients can download the binary file with `GET /api/v1/messages/{message}/media` using `messages:read`; workspace ownership is enforced before the configured disk is read. Successful media downloads are audit logged after the configured disk confirms the file exists.

For S3-compatible media storage, use:

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=larawa-media
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Leave `AWS_ENDPOINT` blank only for AWS S3. For MinIO, R2-compatible gateways, and many other S3-compatible providers, `AWS_ENDPOINT` plus path-style addressing prevents Laravel from generating provider-incompatible bucket hostnames.

## API Documentation

Swagger UI is available outside production at `http://localhost:8080/docs`. The raw OpenAPI document lives at `docs/openapi.yaml` and is served from `/docs/openapi.yaml` outside production. When `APP_ENV=production`, both HTTP documentation routes return `404`. The Swagger UI assets are bundled into the LaraWA image, so API docs do not depend on a public CDN at runtime.

## Live Session Discovery

Ready sessions expose live `whatsapp-web.js` snapshots through the dashboard session detail page and through `GET /api/v1/sessions/{session}/chats`, `/contacts`, and `/groups`. The API endpoints require `sessions:read`, accept `limit` from 1 to 500, and return `409 Conflict` while a session is still waiting for QR scan or reconnect. Message send endpoints accept international phone numbers or WhatsApp chat ids ending in `@c.us` for contacts; the worker verifies the number with WhatsApp and returns `422` if it is not registered. Group sends still require `@g.us` group chat ids; use `/groups` to discover group ids before sending group messages.

## Supported Worker Events

The worker forwards events exposed by `whatsapp-web.js`, including `qr`, `authenticated`, `ready`, `auth_failure`, `disconnected`, `worker.error`, `message.received`, `message.created`, `message.status`, `message.reaction`, `group.join`, `group.leave`, and `status`.

Laravel authenticates worker callbacks with `WA_WORKER_INTERNAL_TOKEN` and validates each event payload before updating sessions, writing message logs, storing inbound media, or queueing webhooks. Worker session ids must be LaraWA-generated UUIDs because they map to persisted `whatsapp-web.js` `LocalAuth` storage. Message callbacks require WhatsApp message ids, ACK callbacks only accept known WhatsApp status values, QR callbacks require QR data, and inbound media callbacks require valid base64 when a media object is present.

Outbound API sends return `202 Accepted` with message status `pending` after `whatsapp-web.js` accepts the send request. `message.status` callbacks reconcile WhatsApp ACK values into message status, `sent_at`, `delivered_at`, and `read_at` timestamps: `ack: -1` becomes `error`, `0` stays `pending`, `1` becomes `sent`, `2` becomes `delivered`, `3` becomes `read`, and `4` becomes `played`. Status updates are monotonic for successful delivery states, so a late error ACK does not downgrade an already-delivered or already-read message. If an ACK arrives before the corresponding `message.created` event, LaraWA creates a sparse row and merges the later message details without duplicating the message.

After upgrading from a build that marked worker-accepted messages as `sent`, run `docker compose exec app php artisan larawa:messages:reconcile-acks --dry-run` to count outgoing rows whose stored WhatsApp ACK state is already `error` but whose top-level status is stale. Run the same command without `--dry-run` to mark those rows as `error`.

Worker callbacks to Laravel retry transient network and 5xx failures with `WA_WORKER_CALLBACK_ATTEMPTS` and `WA_WORKER_CALLBACK_RETRY_DELAY_MS`. This protects startup and restart windows where the worker restores persisted WhatsApp sessions before PHP-FPM is ready.

The worker repeats internal send payload validation before calling `whatsapp-web.js`. `WA_WORKER_JSON_BODY_LIMIT` controls the maximum JSON body accepted by the worker, `WA_WORKER_MEDIA_BASE64_MAX_BYTES` caps decoded base64 media at the internal boundary, `WA_WORKER_MEDIA_URL_MAX_BYTES` caps media downloaded by the worker when API callers use `media_url`, and `WA_WORKER_MEDIA_URL_MAX_REDIRECTS` caps redirect hops while preserving the public-network check for every target.

For first-contact outbound messages, WhatsApp Web can resolve a phone number to a `@lid` identity but later emit `ack: -1` when it cannot actually create or deliver the chat. LaraWA does not create or save contacts automatically, even locally in WhatsApp Web. It stores the normalized `@c.us` recipient on the message row, stores resolved LID metadata in the payload, and updates the message to `error` when that ACK arrives.

Webhook deliveries include `X-LaraWA-Event`, `X-LaraWA-Delivery`, `X-LaraWA-Timestamp`, and `X-LaraWA-Signature: sha256=<hmac>`. Verify the HMAC against `timestamp + "." + raw_json_body` using the webhook secret, then reject timestamps outside your accepted replay window before processing the event.

Use the dashboard webhook `Test` action or `POST /api/v1/webhooks/{webhook}/test` to queue a `webhook.test` delivery through the same signing, queueing, and retry pipeline as live events. Paused webhooks must be enabled before testing. Edit webhook names, URLs, and event subscriptions from the dashboard or with `PATCH /api/v1/webhooks/{webhook}`. Signing secrets are shown once when a webhook is created or rotated; normal list/update responses hide stored secrets. Rotate signing secrets from the dashboard or with `POST /api/v1/webhooks/{webhook}/rotate-secret` when a consumer secret is exposed.

Audit metadata is recursively redacted before storage for secret, token, password, key hash, and one-time key fields. This keeps operational audit trails useful while avoiding accidental persistence of API keys, webhook secrets, or bearer tokens.

Webhook endpoint URLs are public-only by default: LaraWA rejects loopback, private, reserved, and unresolvable hosts before saving webhook configuration. Queued deliveries also re-check the same policy and mark unsafe legacy endpoints as `skipped` without making an HTTP request. This reduces SSRF risk for API keys with `webhooks:write` and for imported database rows. Set `WEBHOOK_URL_ALLOW_PRIVATE=true` only in trusted deployments where webhook deliveries intentionally target internal receivers; the Settings page and `php artisan larawa:doctor --strict` warn when that mode is enabled.

Webhook signing secrets are encrypted at rest when `APP_KEY` is configured. Existing plain-text secret rows remain readable for compatibility; rotating a legacy secret rewrites it using encrypted storage.

## Official Meta WhatsApp Cloud API

Keep `META_GRAPH_API_VERSION=v25.0` unless Meta requires another supported Graph version. First select **Official Cloud API** and create the session. LaraWA generates a unique encrypted verify token and a callback URL containing that session's UUID. Copy both values from the session page into its Meta app, then subscribe the WhatsApp Business Account to the `messages` field:

```text
https://your-larawa-host.example/api/meta/whatsapp/webhook/{session-uuid}
```

After Meta accepts the callback URL and generated token, enter the WABA ID, phone number ID, long-lived system-user access token, and app secret on the LaraWA session page. Saving validates these settings through Graph API and discovers the display phone number from Meta; users do not enter the display phone number. Access tokens, app secrets, and verify tokens are encrypted with `APP_KEY`; access tokens and app secrets are never returned by the API.

The access token and phone number ID authorize and address Graph API message sends. The Meta App Secret is not sent to the `/messages` endpoint; LaraWA uses it locally to validate the `X-Hub-Signature-256` attached to webhook requests. Find the App Secret in **Meta App Dashboard → App settings → Basic**. It belongs to the Meta app, while LaraWA stores an encrypted copy on each Official session so callbacks remain session-scoped.

Wrapper sessions can select a ready Official session in the same workspace as their fallback. Automatic failover is limited to definitive pre-accept failures. Timeouts, ambiguous server errors, and asynchronous delivery failures are not resent automatically because doing so can produce duplicate messages.

Outbound webhook delivery retries are controlled by `WEBHOOK_RETRY_ATTEMPTS` and comma-separated `WEBHOOK_RETRY_BACKOFF` seconds. LaraWA retries network failures, HTTP 408, 429, and 5xx responses until the configured attempt limit is reached, then marks the delivery `exhausted`. Permanent 4xx responses stay failed so invalid endpoints or payload contracts do not create noisy retry loops.

Operators can inspect delivery history in the dashboard or through `GET /api/v1/webhook-deliveries`. Both surfaces can filter by delivery status, concrete event including `webhook.test`, webhook endpoint, and text search across endpoint/event/status/response details. Failed, exhausted, skipped, or pending deliveries can be queued again from the dashboard or with `POST /api/v1/webhook-deliveries/{delivery}/retry`; manual retry resets the attempt counter. Delivered deliveries are terminal and cannot be manually retried to avoid duplicate downstream side effects.

## Release Validation

The first public GitHub release tag is `v13.0`, with LaraWA core version `13.0.0` and OpenAPI `info.version` set to `13.0.0`.

Use `scripts/verify.sh` for the local test, audit, OpenAPI, and Compose configuration gate. Use `scripts/verify.sh --with-compose-up` before release changes that affect Docker startup, SQLite defaults, nginx, the worker image, or dashboard smoke flows. Use `scripts/verify.sh --with-postgres-up` before database, queue, Redis, or PostgreSQL-profile changes.

The GitHub Actions `CI` workflow runs `scripts/verify.sh` on pushes and pull requests. For release candidates, start that workflow manually with `docker_smoke=true` to run the Docker/SQLite smoke in CI as well.

Use `docs/acceptance.md` as the release acceptance matrix. It maps each product criterion to the automated proof available in the repository and the live WhatsApp validation steps that require an enrolled device.

For an already-running stack, `scripts/smoke-dashboard.sh` logs in through the dashboard, creates a scoped API key through the dashboard form, verifies API-key authentication, creates a WhatsApp session through the dashboard form, checks the dashboard log/settings routes, and then cleans up the smoke session/key. Set `LARAWA_SMOKE_ADMIN_EMAIL` and `LARAWA_SMOKE_ADMIN_PASSWORD` when the stack has already been initialized.

For live WhatsApp proof after QR pairing, use `scripts/live-validate.sh`. With `LARAWA_WEBHOOK_ID`, `LARAWA_RESTART_WORKER=1`, and `LARAWA_WAIT_INCOMING_SECONDS=120`, it verifies ready-session persistence after worker restart, text/image send acceptance, stored media download, webhook test delivery status, an incoming marker message in LaraWA, and the corresponding `message.received` webhook delivery.
