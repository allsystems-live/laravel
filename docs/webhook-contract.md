# Webhook contract and configuration reference

## Configuration

Every key in `config/allsystems.php`:

| Key | Env var | Default |
|---|---|---|
| `secret` | `ALLSYSTEMS_WEBHOOK_SECRET` | *(none, required)* |
| `path` | `ALLSYSTEMS_WEBHOOK_PATH` | `webhooks/allsystems` |
| `domain` | `ALLSYSTEMS_WEBHOOK_DOMAIN` | `null` (every host) |
| `tolerance` | `ALLSYSTEMS_WEBHOOK_TOLERANCE` | `300` |
| `cache_key` | | `allsystems:platform-maintenance` |
| `max_minutes` | `ALLSYSTEMS_MAINTENANCE_MAX_MINUTES` | `30` |
| `retry_after` | | `60` |
| `except` | | `['up']` |
| `view` | | `allsystems::maintenance` |
| `json_detail` | | `We're doing some scheduled maintenance. Please try again in a minute or two.` |

Publish the file with `php artisan vendor:publish --tag=allsystems-config`.

## The window

`maintenance.started` opens a window: a flag stored under `cache_key` in the app's default cache store, shared by every instance, so no database is read or written.
`maintenance.ended` closes it.
The flag expires after `max_minutes`, so a lost `ended` cannot keep the app down; the package logs a warning when it finds an expired window.

The flag carries the id of the window that opened it. A redelivered `started` for the open window is a no-op and does not extend it, and an `ended` for any other id is a no-op, so another window's delivery never closes this one.

While the window is open, `PlatformMaintenanceMiddleware` answers every request with a 503, `Retry-After` and `Cache-Control: no-store`: the `view` page for HTML requests, a JSON:API error with code `platform_maintenance` and `json_detail` for requests that expect JSON.
It exempts `up`, the webhook `path` and every `except` entry; exempt paths never read the cache.

## The webhook contract

This section is complete enough to implement a receiver in any language, with nothing else to hand.

`POST {your webhook URL}`

Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `AllSystems-Webhook/1` |
| `X-AllSystems-Event` | `maintenance.started` \| `maintenance.ended` \| `ping` |
| `X-AllSystems-Delivery` | `<delivery id>` (absent on `ping`) |
| `X-AllSystems-Signature` | `t=<unix seconds>,v1=<hex hmac-sha256>` |

**Signature scheme:**

- `v1 = hex(HMAC-SHA256(secret, "{t}.{raw body}"))` — the timestamp, a literal ASCII `.`, then the raw request body, with no re-encoding.
- Reject if `|now - t| > 300` seconds (the replay window).
- Compare digests with a constant-time comparison (`hash_equals()` in PHP), never `==` or `===`.

**The raw-body rule, stated as a rule:** verify against the exact bytes you received on the wire. Never verify against a re-encode of the parsed JSON — re-encoding can change key order, whitespace or unicode escaping, and the digest would then be over something the sender never sent.

**Body shapes.**

`maintenance.started` / `maintenance.ended`:

```json
{
  "id": "01J...",
  "event": "maintenance.started",
  "title": "Database upgrade",
  "message": "Upgrading PostgreSQL. Expect ~20 minutes of downtime.",
  "starts_at": "2026-08-20T01:00:00Z",
  "ends_at": "2026-08-20T01:30:00Z",
  "components": [
    { "id": "01J...", "name": "Web app" },
    { "id": "01J...", "name": "Queue worker" }
  ]
}
```

`ping`:

```json
{ "event": "ping", "application": { "id": "...", "name": "..." } }
```

**Responses.** Every answer is JSON. `200 {"action":"activated"}` and `{"action":"deactivated"}` when the window changed; `{"action":"noop"}` for a redelivered `started`, an `ended` for a window that is not the open one (including "nothing is open") and a `ping`; `{"action":"ignored"}` for an event type this package does not know. `401 {"error":"Invalid signature"}` and `400 {"error":"Invalid payload"}` otherwise.

**Idempotency.** The key is `(id, event)`; see The window for the two noop cases.

**Delivery semantics.** Any `2xx` response is success. Anything else — a non-2xx status, a timeout, or a transport error — is retried, six times over roughly nineteen minutes, and then given up on.

## Golden test vector

Secret, timestamp and body below are fixed constants — check your own HMAC implementation against them in any language in about five minutes.

```
secret    = whsec_test_31d4a1f7c0b84e6fa9d2c5e8b7043f16
timestamp = 1787000000
signed string = "{timestamp}.{raw body}"      (a literal ASCII '.' between them)
v1 = hex(HMAC-SHA256(secret, signed string))  (lowercase hex, as hash_hmac returns)
```

Vector 1 — `maintenance.started` (body is 382 bytes):

```
{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.started","title":"Database upgrade","message":"Upgrading PostgreSQL. Expect ~20 minutes of downtime.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[{"id":"01920000-0000-7000-8000-00000000c001","name":"Web app"},{"id":"01920000-0000-7000-8000-00000000c002","name":"Queue worker"}]}
```

`v1 = c8b3afe8c09a8412359958dcc6c1c86d7bed4318abb431b733c210068c264613`

These same bytes are asserted in this package's `tests/SignatureVectorsTest.php`, and in AllSystems' own test suite.
