<img width="100%" src="assets/readme-header.svg">

<h1 align="center">laravel</h1>

<p align="center"><i>Composer package: receives signed AllSystems maintenance webhooks and answers 503 while a platform maintenance window is open.</i></p>

## What it is

A tenant schedules a maintenance window in AllSystems.
AllSystems posts a signed webhook when the window starts and when it ends.
This package verifies the signature and opens or closes a maintenance window: a flag kept in the app's default cache store, so every instance agrees on it without touching the database a release may be migrating.
The window expires by itself after `allsystems.max_minutes` (30 by default), so a lost `ended` webhook cannot keep the app down.
While it is open, `PlatformMaintenanceMiddleware` answers every request with a 503, `Retry-After` and `Cache-Control: no-store`, except `up` and the webhook path, which must stay reachable so `ended` can land.
It never uses Laravel's own `artisan down` mode.

| Fact | Value |
|---|---|
| Language / runtime | PHP ^8.2 (CI matrix: 8.2, 8.3, 8.4) |
| Framework | Laravel / Illuminate ^12.0 \|\| ^13.0 (Laravel 11 unsupported, see below) |
| Hosting | None — Composer package, no deployed service |
| AWS account | None |
| Primary domain(s) | None |
| GitHub org/repo | `allsystems-live/laravel` |
| Default branch | `main` |

Laravel 11 is dropped: every `laravel/framework` 11.x release is flagged by an unpatched security advisory (11.x is EOL), so Composer's advisory block refuses to resolve it. See `.github/workflows/ci.yml`.

## Where it fits

- Requires `illuminate/cache`, `illuminate/config`, `illuminate/contracts`, `illuminate/http`, `illuminate/routing`, `illuminate/support`, `illuminate/view` (`^12.0 || ^13.0`) — see `composer.json`.
- Counterparty service: [AllSystems](https://github.com/allsystems-live) sends the signed `maintenance.started` / `maintenance.ended` / `ping` webhooks this package verifies and consumes.
- Consumed by any Laravel application that installs `allsystems/laravel` via Composer; consuming applications are not tracked in this repo.

## Install

Add the repository, then require the package:

```bash
composer config repositories.allsystems vcs https://github.com/allsystems-live/laravel
composer require allsystems/laravel:^0.2.0
```

Add `\AllSystems\Laravel\Http\Middleware\PlatformMaintenanceMiddleware::class` to the global middleware, after `HandleCors`: after it so browser callers can read the 503, and before any database work.

The default cache store must be shared across instances (DynamoDB or Redis on Lambda), never `array` or `file`, or instances will disagree about the window.

Set `ALLSYSTEMS_WEBHOOK_SECRET` to the secret shown on the application's page in AllSystems, and register `https://your-app.example/webhooks/allsystems` there as the webhook URL.

## Intercepting a window

An app that needs to claim a maintenance window itself, before the platform window changes, implements `AllSystems\Laravel\Contracts\MaintenanceInterceptor` and binds it.

`started()` runs for a signed, valid `maintenance.started` and `ended()` for a signed, valid `maintenance.ended`. Neither runs for a ping, an unknown event, a `401` or a `400`.

Returning `activated` or `noop` from `started()`, or `deactivated` from `ended()`, makes that value the webhook answer and leaves the platform window alone. Returning null, or any other value, falls through to the normal behaviour: the platform window opens or closes.

```php
use AllSystems\Laravel\Contracts\MaintenanceInterceptor;

$this->app->bind(MaintenanceInterceptor::class, YourInterceptor::class);
```

Put the binding in a service provider's `register()`. With nothing bound, the package behaves as before.

## Run it

Prerequisites: PHP 8.2–8.4, Composer.

```bash
composer install
```

Run the tests:

```bash
composer test
```

Quality gates, all green before any PR (`composer check` runs all three):

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/phpunit
```

## How it ships

No deploy: this is a public Composer package with no infrastructure stack, artifact key or dispatch payload.
Merge to `main` triggers CI only (`.github/workflows/ci.yml`, the matrix above).
Releases are cut by Josh with release-train: plan the release candidate, then cut it, then promote it.

```bash
gh release-train rc -R allsystems-live/laravel minor --plan-only
gh release-train rc -R allsystems-live/laravel minor
gh release-train promote -R allsystems-live/laravel
```

Branch and PR rules are in `CLAUDE.md`.

## Layout

| Path | Purpose |
|---|---|
| `src/` | Service provider, `PlatformMaintenance` window, system clock, webhook controller, `VerifySignature` and `PlatformMaintenanceMiddleware` |
| `config/` | `config/allsystems.php` — the only config file |
| `resources/views/` | `maintenance.blade.php` — the bundled 503 page |
| `routes/` | `routes/webhook.php` — the webhook route |
| `tests/` | PHPUnit tests, flat (no `plugins/*/tests` split) |
| `assets/` | README banner |

## Rules and docs

- Rules: `CLAUDE.md`.
- Full webhook contract, the maintenance window, config reference and golden HMAC test vector: `docs/webhook-contract.md`.
- Specs: `docs/superpowers/specs/{proposed,active,done}` (not present in this repo yet; the estate convention).
- Plans: `docs/superpowers/plans` (not present in this repo yet; the estate convention).
