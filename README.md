# allsystems/laravel

Receive [AllSystems](https://github.com/allsystems-live) maintenance webhooks and drive Laravel's own maintenance mode automatically.

## What it does

A tenant schedules a maintenance window in AllSystems. AllSystems posts a signed webhook to your application when the window starts and when it ends. This package verifies the signature and puts your Laravel app into, and back out of, maintenance mode via `Illuminate\Contracts\Foundation\MaintenanceMode` — nobody runs `artisan down`.

## Install

```bash
composer require allsystems/laravel
php artisan vendor:publish --tag=allsystems-config
```

Set the two env vars that matter:

```
ALLSYSTEMS_WEBHOOK_SECRET=whsec_...
ALLSYSTEMS_WEBHOOK_PATH=allsystems/webhook   # optional, this is the default
```

Then, in AllSystems, register `https://your-app.example/allsystems/webhook` as the application's webhook URL and press **Send test ping**.

## Configuration

Every key in `config/allsystems.php`:

| Key | Env var | Default |
|---|---|---|
| `secret` | `ALLSYSTEMS_WEBHOOK_SECRET` | *(none — required)* |
| `path` | `ALLSYSTEMS_WEBHOOK_PATH` | `allsystems/webhook` |
| `tolerance` | `ALLSYSTEMS_WEBHOOK_TOLERANCE` | `300` |
| `maintenance.enabled` | `ALLSYSTEMS_MAINTENANCE_ENABLED` | `true` |
| `maintenance.secret` | `ALLSYSTEMS_MAINTENANCE_BYPASS_SECRET` | `null` |
| `maintenance.template` | `ALLSYSTEMS_MAINTENANCE_TEMPLATE` | `null` |
| `maintenance.redirect` | `ALLSYSTEMS_MAINTENANCE_REDIRECT` | `null` |
| `maintenance.refresh` | `ALLSYSTEMS_MAINTENANCE_REFRESH` | `null` |

`maintenance.secret`, `maintenance.template`, `maintenance.redirect` and `maintenance.refresh` are your app's own maintenance-mode configuration, passed straight through to Laravel's `MaintenanceMode::activate()` — the same options `artisan down --secret= --render= --redirect= --refresh=` accepts. AllSystems never sends any of this and never sees it.

## Maintenance drivers

This package has no opinion on, and no configuration for, which driver backs Laravel's maintenance mode. That is your app's own `APP_MAINTENANCE_DRIVER` choice — file, cache, or anything else — and it is yours to make. See [Laravel's maintenance-mode documentation](https://laravel.com/docs/configuration#maintenance-mode).

One real consequence of driving the contract through `MaintenanceMode::activate()` rather than `artisan down`: the pre-rendered `storage/framework/maintenance.php` short-circuit file is **not** written. Requests still boot the framework — which is precisely what lets the webhook route stay reachable and lets the app come back up when `maintenance.ended` arrives.

## Events

Three events are dispatched, always, regardless of `maintenance.enabled`:

- **`MaintenanceStarted`** — carries a `MaintenancePayload` (`id`, `title`, `message`, `startsAt`, `endsAt`, `components`) built from the `maintenance.started` body.
- **`MaintenanceEnded`** — carries the same `MaintenancePayload` shape, built from the `maintenance.ended` body.
- **`PingReceived`** — carries `applicationId` and `applicationName`, built from a `ping` body.

A copy-pasteable listener for an app that wants to pause its own queue workers during a window:

```php
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\Events\MaintenanceEnded;
use Illuminate\Support\Facades\Event;

Event::listen(MaintenanceStarted::class, function (MaintenanceStarted $event) {
    Artisan::call('queue:pause');
});

Event::listen(MaintenanceEnded::class, function (MaintenanceEnded $event) {
    Artisan::call('queue:resume');
});
```

Set `ALLSYSTEMS_MAINTENANCE_ENABLED=false` for apps that want these events without the package's default `SyncMaintenanceMode` behaviour — the webhook still verifies and still dispatches, only the bundled listener is not registered.

## What it will never do to you

The id guard: an `artisan down` you ran by hand is never lifted by AllSystems, and neither is another window's. `SyncMaintenanceMode` only calls `deactivate()` when the currently-active maintenance mode carries the id of the window it is being asked to end:

```php
final readonly class SyncMaintenanceMode
{
    /** The marker that makes a maintenance mode ours. */
    public const ID_KEY = 'allsystems_maintenance_id';
}
```

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

**Idempotency.** The key is `(id, event)`. Two documented noop cases: a redelivered `maintenance.started` for a window that is already the active one is a `noop` `200`; a `maintenance.ended` for a window that is not the currently-active one (including "nothing is active") is also a `noop` `200`.

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

## Testing

```bash
composer test
```

## Contributing

Issues and pull requests are welcome at [github.com/allsystems-live/laravel](https://github.com/allsystems-live/laravel). Before opening a PR, run:

```bash
composer check
```

which runs Pint, PHPStan and the PHPUnit suite.

## License

MIT. See [LICENSE](LICENSE).
