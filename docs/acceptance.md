# LaraWA Acceptance Evidence

This matrix maps the product acceptance criteria to the strongest available proof in the repository. Local automation proves the platform, dashboard, API, database profiles, worker contract, and security controls. The WhatsApp network criteria require a real WhatsApp account because QR pairing and message delivery happen outside LaraWA.

Run the default local gate:

```bash
scripts/verify.sh
```

Run the default Docker/SQLite gate:

```bash
scripts/verify.sh --with-compose-up
```

Run the PostgreSQL and Redis profile gate:

```bash
scripts/verify.sh --with-postgres-up
```

Run live WhatsApp validation after pairing a disposable account:

```bash
LARAWA_API_KEY=lwa_your_key_here \
LARAWA_SESSION_UUID=019e8d53-9e58-73b0-b7c1-e01c5c259d14 \
LARAWA_RECIPIENT='+1 202-555-0100' \
LARAWA_WEBHOOK_ID=1 \
LARAWA_RESTART_WORKER=1 \
LARAWA_WAIT_INCOMING_SECONDS=120 \
scripts/live-validate.sh
```

## Criteria

| # | Criterion | Evidence |
|---|---|---|
| 1 | User can login. | `scripts/smoke-dashboard.sh` completes first-run setup when needed, then logs in through `/login`; `scripts/verify.sh --with-compose-up` runs it against Docker. Feature tests cover setup, successful login, throttled failures, and disabled-account rejection. |
| 2 | User can create a WhatsApp session. | Dashboard smoke creates a session through the dashboard form and finds it through the API. Feature tests cover dashboard and API session creation plus worker failure handling. |
| 3 | QR code is displayed. | Feature tests sync a worker `qr_data_url` into Laravel and assert the dashboard renders `<img alt="WhatsApp QR code" src="data:image/png...">`. |
| 4 | WhatsApp account can connect successfully. | Requires live validation with a phone. The worker uses `whatsapp-web.js` `LocalAuth`, emits `authenticated` and `ready`, and the live runbook verifies dashboard/API `ready` state after QR scan. |
| 5 | Session survives container restart. | Worker unit tests prove registry persistence and LocalAuth directory cleanup semantics. Docker Compose mounts `larawa_worker_sessions`. `scripts/live-validate.sh` with `LARAWA_RESTART_WORKER=1` verifies an authenticated live session returns to `ready` after `docker compose restart wa-worker`. |
| 6 | User can create API keys. | Dashboard smoke creates a scoped key through the dashboard and then authenticates with it. Feature tests cover hashed storage, one-time key display, scopes, rotation, expiration, IP allow lists, and revocation. |
| 7 | API can send text messages. | Feature tests verify `POST /api/v1/sessions/{session}/messages/text` stores an outgoing row and calls the worker. Live validation sends a real text message through a ready session. |
| 8 | API can send image messages. | Feature tests verify image send, media storage, worker dispatch, and download. Live validation sends a real PNG image and downloads the stored media. |
| 9 | Incoming messages trigger webhooks. | Feature tests cover worker `message.received`, message persistence, signed webhook delivery, retry behavior, and HMAC headers. Live validation waits for an incoming marker message and verifies a newer `message.received` webhook delivery reaches `delivered`. |
| 10 | Dashboard displays sessions, messages, webhook logs, and audit logs. | Dashboard smoke checks the sessions, messages, webhooks, audit logs, and settings routes. Feature tests cover filters and workspace isolation for sessions, messages, webhook deliveries, and audit logs. |
| 11 | SQLite works by default. | `.env.example` uses SQLite defaults. `scripts/verify.sh --with-compose-up` starts Docker with `.env.example` and asserts `/healthz` reports `sqlite`. |
| 12 | PostgreSQL and Redis can be enabled without losing data on container recreation. | `.env.postgres.example` enables the `postgres` profile with database-backed services, and `.env.redis-postgres.example` adds Redis-backed cache, queues, and sessions. `scripts/verify.sh --with-postgres-up` starts an isolated stack, asserts `/healthz` reports `pgsql`, writes a persistence marker, force-recreates the PostgreSQL container, and proves the marker remains in the named volume. |
| 13 | Enterprise RBAC is enforced. | `tests/Feature/EnterpriseRbacTest.php` covers `site_admin`, `workspace_admin`, and `workspace_user` dashboards, server-side 403 responses, user/workspace management, workspace-admin invites, read-only workspace users, and suspended-workspace API-key rejection. |

## Enterprise RBAC Roles

- `site_admin`: platform owner with global user, workspace, session, API key, audit log, and system-settings access. Site admin access is stored on the system admin workspace, which cannot be suspended or deleted; the initial user cannot be disabled or deleted.
- `workspace_admin`: workspace manager with user, session, API key, webhook, message, audit log, and settings access only for assigned workspaces.
- `workspace_user`: regular workspace member with read access to assigned workspace sessions, messages, and webhook logs.

Dashboard menus are role-aware, but authorization is enforced in Laravel controllers and middleware. Unauthorized dashboard requests return `403`; API keys for suspended workspaces also return `403`.

## Completion Boundary

Do not treat local tests alone as proof that criteria 4, 5, 7, 8, and 9 are fully satisfied in production. They exercise LaraWA code paths, but final acceptance for those criteria requires the live WhatsApp run against an account you control, plus confirmation in the receiver account and webhook receiver.
