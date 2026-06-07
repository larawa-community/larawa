# Security Policy

LaraWA handles WhatsApp browser sessions, API keys, webhook secrets, message logs, and media files. Please report suspected vulnerabilities privately.

## Reporting a Vulnerability

Use GitHub private vulnerability reporting when the repository is hosted on GitHub. If private reporting is not available, contact a maintainer through a private channel before publishing details. Do not open a public issue for active vulnerabilities.

Include:

- Affected version or commit.
- Deployment mode, database, queue, cache, and storage backend.
- Steps to reproduce.
- Expected and observed impact.
- Relevant logs with secrets, API keys, webhook signatures, phone numbers, message bodies, and media removed.

## Supported Security Expectations

LaraWA is designed to support:

- Hashed API key storage with one-time plain-text display.
- Scoped API keys, per-key rate limiting, IP allow lists, expiry, revocation, and rotation.
- Worker internal API authentication with a non-empty shared `WA_WORKER_INTERNAL_TOKEN`, UUID-only worker session identifiers for `LocalAuth` storage, and event-specific callback payload validation before database writes or webhook dispatch.
- Webhook HMAC signatures and encrypted webhook signing secrets when `APP_KEY` is configured.
- Audit logs for session, API key, webhook, message, and worker-event operations, with recursive redaction for secret, token, password, key hash, and one-time key fields in audit metadata.
- Docker volume persistence for WhatsApp `LocalAuth` data.

## Operator Responsibilities

- Set strong `APP_KEY`, `WA_WORKER_INTERNAL_TOKEN`, first administrator password, and database credentials before exposing the service.
- Use HTTPS in front of nginx for production. The bundled nginx config emits baseline browser security headers; add HSTS at the TLS terminator that owns certificates.
- Limit dashboard access with network controls or an authenticated reverse proxy when possible.
- Dashboard login attempts are rate limited by email address and client IP.
- The one-time installer writes only allowlisted `.env` keys, rejects new-line values, serializes setup through a filesystem lock, and disables itself after `LARAWA_INSTALLED=true` or an existing `site_admin`.
- Use `LOG_LEVEL=info` or higher in production to reduce message and operational detail written to application logs.
- Prefer least-privilege API key scopes and IP allow lists.
- Rotate exposed API keys and webhook secrets immediately.
- Back up SQLite/PostgreSQL data and media/session volumes according to your recovery objectives.
- Keep Docker build contexts clean. LaraWA excludes local env files, logs, SQLite databases, runtime storage, dependency folders, tests, and stale frontend builds from images; do not override those excludes with local secrets or production data.
- Keep Docker base images and npm/composer dependencies updated. `scripts/verify.sh` runs Composer and npm production dependency audits for the Laravel app and worker.

## WhatsApp Account Safety

LaraWA is an independent community project and is not affiliated with, sponsored by, or endorsed by Meta Platforms, Inc., WhatsApp LLC, or their affiliates. LaraWA uses WhatsApp Web automation through `whatsapp-web.js`. Operators are responsible for using WhatsApp accounts in compliance with WhatsApp and Meta terms, policies, and applicable law. Use a dedicated test account for validation and avoid sending unsolicited or abusive traffic.
