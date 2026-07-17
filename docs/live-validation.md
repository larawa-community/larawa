# LaraWA Live WhatsApp Validation

Use this runbook to verify the acceptance criteria that require a real WhatsApp device and WhatsApp Web session. Automated tests cover Laravel, worker APIs, idempotency, persistence mechanics, webhooks, and security controls, but they cannot prove QR scanning or live WhatsApp delivery without an enrolled account.

## Prerequisites

- A disposable WhatsApp test account on a phone you control.
- A second WhatsApp account or group for send/receive testing.
- A public HTTPS webhook receiver, such as an internal test endpoint, webhook.site, or an HTTPS tunnel to a local receiver.
- A fresh `.env` with strong `APP_KEY` and `WA_WORKER_INTERNAL_TOKEN`, plus a completed one-time dashboard setup.

Start LaraWA:

```bash
docker compose up -d --build
curl -fsS http://localhost/healthz
docker compose ps
```

Expected result: `app`, `nginx`, `queue`, `scheduler`, `wa-worker`, and `redis` are running and healthy.

After the session is paired and you have a scoped API key, you can run the repeatable API-side checks with:

```bash
LARAWA_API_KEY=lwa_your_key_here \
LARAWA_SESSION_UUID=019e8d53-9e58-73b0-b7c1-e01c5c259d14 \
LARAWA_RECIPIENT='+1 202-555-0100' \
LARAWA_WEBHOOK_ID=1 \
LARAWA_WAIT_INCOMING_SECONDS=120 \
LARAWA_RESTART_WORKER=1 \
scripts/live-validate.sh
```

Optional inputs:

- `LARAWA_WEBHOOK_ID=1` queues a signed `webhook.test` delivery.
- `LARAWA_GROUP_ID=120363000000000000@g.us` sends a group text.
- `LARAWA_RESTART_WORKER=1` restarts `wa-worker` and confirms the session returns to `ready`.
- `LARAWA_RUN_OPTIONAL_SENDS=1` also sends document, audio, and reaction requests.
- `LARAWA_IMAGE_PATH=./validation-image.png` uses your own PNG instead of the generated 1x1 validation image.
- `LARAWA_WAIT_WEBHOOK_SECONDS=60` changes how long the script waits for webhook deliveries to reach `delivered`.
- `LARAWA_WAIT_INCOMING_SECONDS=120` asks the script to wait for a real incoming WhatsApp message containing a generated marker.
- `LARAWA_INCOMING_MARKER="LaraWA validation reply"` sets the marker text to send from the second account.

The script proves API behavior, worker acceptance, webhook delivery status, and, when `LARAWA_WAIT_INCOMING_SECONDS` is set, that an inbound WhatsApp message reached the LaraWA message log. You still need to confirm in WhatsApp and your webhook receiver that the remote account saw the outgoing messages and that the receiver accepted the signed payloads.

## 1. Login And Create A Session

1. Open `http://localhost`.
2. Login with the configured admin account.
3. Open `Sessions`.
4. Create a session named `Live Validation`.
5. Open the session detail page.

Expected result:

- The session appears in the dashboard.
- Status becomes `qr`.
- A QR code is visible.
- An audit log entry is created for session creation.

## 2. Pair WhatsApp By QR

1. On the phone, open WhatsApp.
2. Go to Linked devices.
3. Scan the QR code from the LaraWA session page.
4. Wait up to 60 seconds.

Expected result:

- Dashboard session status becomes `ready`.
- QR code disappears.
- Phone number/platform metadata appears when WhatsApp Web exposes it.
- `GET /api/v1/sessions/{session}` returns `status=ready`.

## 3. Verify Persistence Across Restart

Restart the worker without deleting the session:

```bash
docker compose restart wa-worker
sleep 20
docker compose exec app php artisan larawa:sessions:sync
```

Expected result:

- The session remains present in the dashboard.
- Status returns to `ready` without scanning a new QR code.
- The phone still shows LaraWA as a linked device.
- `larawa_worker_sessions` remains populated.

## 4. Create API Key

1. Open `API Keys`.
2. Create a key with `sessions:read`, `messages:send`, and `messages:read`. Add `webhooks:read` and `webhooks:write` when validating `LARAWA_WEBHOOK_ID`, incoming webhook delivery, or the dashboard webhook test action.
3. Copy the one-time plain-text key.

Expected result:

- The key appears with only its prefix.
- The plain-text key is not shown again after refresh.
- `GET /api/v1/sessions` succeeds with the key.

## 5. Send Text Message

```bash
curl -X POST http://localhost/api/v1/sessions/{session_uuid}/messages/text \
  -H "Authorization: Bearer ${LARAWA_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"to":"+1 202-555-0100","text":"LaraWA live validation text","idempotency_key":"live-text-001"}'
```

Expected result:

- API returns `202`.
- Message appears in WhatsApp for the recipient.
- Dashboard `Messages` shows an outgoing text row.
- Audit logs include `api.message.sent`.

## 6. Send Image Message

```bash
IMAGE_BASE64="$(base64 -i ./validation-image.png | tr -d '\n')"
curl -X POST http://localhost/api/v1/sessions/{session_uuid}/messages/image \
  -H "Authorization: Bearer ${LARAWA_API_KEY}" \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"+1 202-555-0100\",\"media_base64\":\"${IMAGE_BASE64}\",\"mime_type\":\"image/png\",\"filename\":\"validation-image.png\",\"caption\":\"LaraWA live validation image\",\"idempotency_key\":\"live-image-001\"}"
```

Expected result:

- API returns `202`.
- Image appears in WhatsApp for the recipient.
- Dashboard `Messages` shows an outgoing image row.
- Stored media can be downloaded from the dashboard or `GET /api/v1/messages/{message}/media`.

## 7. Send Video, Document, Audio, Reaction, And Group Message

Use the same session and API key:

- `POST /api/v1/sessions/{session}/messages/video`
- `POST /api/v1/sessions/{session}/messages/document`
- `POST /api/v1/sessions/{session}/messages/audio`
- `POST /api/v1/sessions/{session}/messages/reaction`
- `POST /api/v1/sessions/{session}/messages/text` with a group id ending in `@g.us`

Discover group ids with:

```bash
curl http://localhost/api/v1/sessions/{session_uuid}/groups \
  -H "Authorization: Bearer ${LARAWA_API_KEY}"
```

Expected result:

- Each API call returns success or a clear WhatsApp/worker error.
- Successful sends appear in message logs.
- Message status updates appear when WhatsApp Web emits acknowledgements.

## 8. Verify Incoming Webhook Delivery

1. Open `Webhooks`.
2. Create a webhook pointing to your HTTPS receiver with `message.received` and `message.status`.
3. Use the dashboard `Test` action.
4. Send a WhatsApp message from the second account to the paired account.

Expected result:

- The test delivery reaches the receiver with `X-LaraWA-Timestamp` and `X-LaraWA-Signature`.
- Incoming WhatsApp message appears in dashboard `Messages`.
- Webhook receiver gets a `message.received` event.
- Dashboard `Webhooks` shows delivery status and response code.
- HMAC verifies against `timestamp + "." + raw_json_body` and the one-time webhook secret shown when the endpoint was created or rotated; the timestamp is inside your receiver's accepted replay window.

For repeatable proof, run `scripts/live-validate.sh` with `LARAWA_WEBHOOK_ID` and `LARAWA_WAIT_INCOMING_SECONDS`. The script records the latest `message.received` delivery before prompting for the marker, waits until the marker appears in `GET /api/v1/messages`, and then verifies a newer webhook delivery reaches `delivered`.

## 9. Verify Logs And Operations

Check:

- `Sessions` shows current state.
- `Messages` shows incoming and outgoing messages.
- `Webhooks` shows delivery logs and retry controls.
- `Audit Logs` shows session, API key, message, and webhook actions.
- `Settings` diagnostics have no critical findings for the chosen environment.

## 10. Cleanup

When validation is complete:

1. Revoke test API keys.
2. Delete test webhooks.
3. Delete the WhatsApp session from LaraWA if the linked device should be removed.
4. Confirm the linked device disappears from the phone.

Keep the session if you are validating persistence over a longer soak period.
