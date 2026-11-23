# Chunk 3 brief: AS-385

- Project skills: none

## Chunk 3: The gate, the page and the docs

Files:
- Create: src/Http/Middleware/PlatformMaintenanceMiddleware.php
- Create: resources/views/maintenance.blade.php
- Modify: src/AllSystemsServiceProvider.php
- Modify: config/allsystems.php
- Modify: composer.json
- Modify: README.md
- Modify: docs/webhook-contract.md
- Modify: CHANGELOG.md
- Modify: CLAUDE.md
- Test: tests/PlatformMaintenanceMiddlewareTest.php
- Test: tests/ServiceProviderTest.php

Interfaces: consumes PlatformMaintenance (chunk 1) and the final path config (chunk 2). Hands the app `AllSystems\Laravel\Http\Middleware\PlatformMaintenanceMiddleware` and view namespace `allsystems`.

Behaviour:
- PlatformMaintenanceMiddleware: Kiln's PlatformMaintenanceMiddleware.php, `final`, constructor (PlatformMaintenance, ResponseFactory, ConfigRepository). Exempt list is `[config('allsystems.path'), ...config('allsystems.except')]`, filtered to strings for phpstan, matched with `$request->is(...)`, checked before `isActive()` so exempt paths never read the cache. When active: headers `Retry-After` (string of `allsystems.retry_after`) and `Cache-Control: no-store`; `expectsJson()` gets the JSON:API body with `detail` from `allsystems.json_detail` and `Content-Type: application/vnd.api+json`; otherwise `view(config('allsystems.view'), [], 503, $headers)`. No DB access anywhere on the path.
- resources/views/maintenance.blade.php: the Earnly view below with both "Earnly" replaced by `{{ config('app.name') }}`.
- Provider boot(): `$this->loadViewsFrom(__DIR__ . '/../resources/views', 'allsystems')`.
- config/allsystems.php: add `retry_after => 60`, `except => ['up']`, `view => 'allsystems::maintenance'`, `json_detail => "We're doing some scheduled maintenance. Please try again in a minute or two."`, each with a one-line comment. Final key set is exactly the ticket's ten flat keys.
- composer.json `description`: replace "drive Laravel's maintenance mode automatically" with wording for the cache-flag window (no infra vendor names needed).
- README.md: "What it is" describes the cache-flag window, its expiry and the gate; new "Install" section (VCS repository entry for `https://github.com/allsystems-live/laravel`, `composer require allsystems/laravel:^0.1.0`, add `\AllSystems\Laravel\Http\Middleware\PlatformMaintenanceMiddleware::class` to global middleware after HandleCors and why: after CORS so browser callers read the 503, before any database work; the default cache store must be shared across instances, DynamoDB or Redis on Lambda, never array or file; `ALLSYSTEMS_WEBHOOK_SECRET`); "How it ships" gains the two release-train commands verbatim from the ticket (`gh release-train rc -R allsystems-live/laravel minor --plan-only`, then without `--plan-only`; `gh release-train promote -R allsystems-live/laravel`), Josh-run. Update Layout for the new/removed files. Keep the estate README standard's section order.
- docs/webhook-contract.md: replace "Maintenance drivers", "Events" and "The id guard" with one "The window" section (cache key, default store, max_minutes expiry and its warning, id semantics, exemptions `up` + webhook path + `except`); Configuration lists the ten flat keys; response bodies become `{"action":…}` (activated, deactivated, noop, ignored) and `{"error":"Invalid signature"}` / `{"error":"Invalid payload"}`; default path `webhooks/allsystems`. Keep "Golden test vector" unchanged.
- CHANGELOG.md: rename `## Unreleased` to `## v0.1.0` and rewrite its entries for this implementation.
- CLAUDE.md: its one-line description says the package drives Laravel's own maintenance mode; change it to the cache-flag window.

The view to port (Earnly PR #58, getearnly-io/earnly branch build/EARN-203-earnly-receives-allsystems-maintenance-w, `application/resources/views/errors/platform-maintenance.blade.php`):

    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Earnly – back in a minute</title>
        <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; text-align: center; }
            main { padding: 24px; }
            h1 { font-size: 1.75rem; margin: 0 0 12px; }
            p { margin: 0; color: #475569; }
        </style>
    </head>
    <body>
        <main>
            <h1>We're updating Earnly</h1>
            <p>We'll be back in a minute. Please try again shortly.</p>
        </main>
    </body>
    </html>

Test scenarios (tests/PlatformMaintenanceMiddlewareTest.php, Kiln's feature PlatformMaintenanceTest made generic; push the middleware onto the kernel's global stack and register throwaway GET `/` and POST `/other` routes in the test):
- an open window answers `/` with 503, `Retry-After: 60`, `Cache-Control: no-store`, and the HTML page containing `<title>{app.name} – back in a minute</title>`, `We're updating {app.name}` and the "We'll be back in a minute" text, with no asset URL;
- a JSON request gets 503 with `application/vnd.api+json` and the exact JSON:API body including `code: platform_maintenance` and the default detail;
- `up` and a signed `maintenance.ended` to the webhook path are not answered by the gate (the ended closes the window);
- POST `/other` gets the 503;
- an extra `allsystems.except` path passes;
- with no window open nothing is turned away and, with `app.maintenance.store = database`, `DB::listen` records zero queries across the request;
- a started window still 503s with `app.maintenance.store = database`;
- `allsystems.retry_after`, `allsystems.json_detail` and `allsystems.view` overrides are honoured (one case each, or one combined).
ServiceProviderTest: config-defaults test extended to the four new keys; `view()->exists('allsystems::maintenance')` is true.

ACs met: one cache read and no DB query with no window; 503 gate with Retry-After and no-store and exemptions; JSON:API body; bundled self-contained view; composer check green on the matrix (final gate run).


## Touched-test command

In the worktree: `vendor/bin/phpunit tests/PlatformMaintenanceMiddlewareTest.php tests/ServiceProviderTest.php`; full suite `composer check`. Gates from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-385-allsystems-laravel-takes-kiln-s-receiver` (`--only <gate>` while iterating).

## Report

.superpowers/sdd/AS-385-allsystems-laravel-takes-kiln-s-receiver/chunk-3-report.md (commit it with git add -f)
