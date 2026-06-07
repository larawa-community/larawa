# Contributing to LaraWA

Thanks for helping improve LaraWA. The project is a Docker-first Laravel API/dashboard plus a Node.js `whatsapp-web.js` worker, so changes should preserve the self-hosted deployment path and the security model around API keys, webhooks, and worker callbacks.

## Local Development

```bash
cp .env.example .env
composer install
npm install --ignore-scripts
php artisan key:generate
php artisan migrate --seed
npm run build
```

For the full production-like stack:

```bash
docker compose up -d --build
```

Use PostgreSQL with database-backed cache, queues, and sessions with:

```bash
cp .env.postgres.example .env
docker compose --profile postgres up -d --build
```

Use PostgreSQL plus Redis-backed cache, queues, and sessions with:

```bash
cp .env.redis-postgres.example .env
docker compose --profile postgres up -d --build
```

## Checks Before Opening a PR

Run the same checks used by CI:

```bash
scripts/verify.sh
```

If your change touches Docker startup, worker callbacks, session persistence, or dashboard routes, also verify:

```bash
scripts/verify.sh --with-compose-up
```

Maintainers can run the same Docker/SQLite smoke in GitHub Actions by starting the `CI` workflow manually and enabling the `docker_smoke` input.

If your change touches database configuration, Redis-backed queues/cache/sessions, migrations, or deployment profiles, verify the PostgreSQL profile too:

```bash
scripts/verify.sh --with-postgres-up
```

## Pull Request Guidance

- Keep changes scoped to one behavior or production concern.
- Add or update feature tests for API, dashboard, worker callback, and security-sensitive behavior.
- Keep Composer, dashboard npm, and worker npm production dependency audits clean; `scripts/verify.sh` runs these advisory checks.
- Update `docs/openapi.yaml` whenever REST routes, request fields, response bodies, or status codes change.
- Update `README.md` or `docs/setup.md` when deployment, operations, security, or user workflows change.
- Avoid wording, visuals, or metadata that implies LaraWA is official, endorsed by, sponsored by, or affiliated with Meta or WhatsApp.
- Do not commit local `.env`, WhatsApp session data, browser profiles, SQLite files, media files, or secrets.

## WhatsApp Validation

Automated tests mock the `whatsapp-web.js` worker boundary. Changes that affect QR pairing, real message delivery, worker restart behavior, media delivery, groups, or incoming events should also be checked with `docs/live-validation.md` against a disposable WhatsApp test account.
