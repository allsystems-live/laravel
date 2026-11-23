# AS-385: allsystems/laravel takes Kiln's receiver; v0.1.0
Ticket: AS-385 @ 2026-11-23T20:08:01.653+0000

## Global Constraints

- All gates green after every chunk, run from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-385-allsystems-laravel-takes-kiln-s-receiver` (pint-test, analyse at phpstan level 9 over src and tests, phpunit). Use `--only pint` / `--only test` while iterating. CI must hold on all 5 matrix cells (PHP 8.2–8.4 × Laravel 12–13), so no PHP 8.3-only syntax: class constants are untyped (`private const KEY = ...`, never `private const string`).
- No weakened invariant: the HMAC check stays hash_equals over the raw body, fail-closed on an empty secret; no test is deleted except those the ticket lists for deletion; no auto-merge.
- The package never registers PlatformMaintenanceMiddleware itself; the app adds it after HandleCors.
- The window lives only in `$cache->store()` under `allsystems.cache_key`; nothing reads or writes Laravel's MaintenanceMode or `app.maintenance.store`.
- The ported source is Kiln at origin/main, under `application/` (the ticket's paths omit that prefix): `application/app/Miscellaneous/Services/PlatformMaintenance.php`, `application/app/Miscellaneous/Http/Middleware/{PlatformMaintenanceMiddleware,VerifyAllSystemsSignature}.php`, `application/app/Miscellaneous/Http/Controllers/AllSystemsWebhookController.php`, and tests `application/tests/Unit/Miscellaneous/Services/PlatformMaintenanceTest.php`, `application/tests/Feature/Miscellaneous/Http/{PlatformMaintenanceTest,AllSystemsWebhookTest}.php`. Read them with `git -C ~/dev/agent-farm/kiln show origin/main:<path>`. Kiln's config keys (`app.maintenance.max_minutes`, `services.allsystems.webhook_secret`, `services.allsystems.tolerance`) become `allsystems.max_minutes`, `allsystems.secret`, `allsystems.tolerance`.
- The webhook path default changes from `allsystems/webhook` to `webhooks/allsystems`. Grep the whole tree (src, routes, config, tests, README, docs, CLAUDE.md) for `allsystems/webhook` and update every occurrence, including the provider's fallback literal and test `PATH` constants.
- Not touched: `.github/` (CI matrix and php.json), `phpunit.xml`, `phpstan.neon`, `pint.json`, `tests/SkeletonTest.php`. No `version` field in composer.json, no Packagist, no release-train group. The release (`gh release-train rc/promote`) is post-merge and Josh-run: not part of this build.
- Out of scope: vehicle-lookup notice, storefront purge, SPA notice, admin bypass, Laravel 11.
- Project skills: none

## Chunk 1: The window, the clock and the new config keys

Files:
- Create: src/PlatformMaintenance.php
- Create: src/SystemClock.php
- Modify: src/AllSystemsServiceProvider.php
- Modify: config/allsystems.php
- Modify: composer.json
- Test: tests/PlatformMaintenanceTest.php
- Test: tests/ServiceProviderTest.php

Interfaces: hands later chunks `AllSystems\Laravel\PlatformMaintenance` with `start(string $id): string` ('activated'|'noop'), `end(string $id): string` ('deactivated'|'noop'), `isActive(): bool`; `AllSystems\Laravel\SystemClock`; `Psr\Clock\ClockInterface` bound via `bindIf`; config keys `allsystems.cache_key` and `allsystems.max_minutes`.

Behaviour:
- PlatformMaintenance is Kiln's PlatformMaintenance.php ported verbatim except: namespace `AllSystems\Laravel`; drop RETRY_AFTER_SECONDS (retry_after is config in chunk 3); KEY constant replaced by reading `allsystems.cache_key` from config; maxMinutes reads `allsystems.max_minutes`; constants untyped. Constructor (CacheFactory, ConfigRepository, ClockInterface, LoggerInterface), state `{id, started_at, expires_at}` via `forever()`, logs `platform_maintenance.started|ended` (info, `['id']`) and `platform_maintenance.expired` (warning, `id` + `started_at` as DATE_ATOM) unchanged. The cache key read must be a string for phpstan level 9 (cast or narrow with a fallback to the default).
- SystemClock: `final class SystemClock implements ClockInterface`, `now(): DateTimeImmutable { return new DateTimeImmutable(); }`.
- Provider register(): add `$this->app->bindIf(ClockInterface::class, SystemClock::class)`. Leave the SyncResult binding, listener and `maintenance.*` handling alone in this chunk (removed in chunk 2) so the old suite stays green.
- config/allsystems.php: add flat top-level keys `cache_key => 'allsystems:platform-maintenance'` and `max_minutes => (int) env('ALLSYSTEMS_MAINTENANCE_MAX_MINUTES', 30)`, each with a one-line comment. Leave the `maintenance` array for now.
- composer.json require: add `illuminate/cache` and `illuminate/view` (`^12.0 || ^13.0`), `psr/clock: ^1.0`, `psr/log: ^3.0`; require-dev: `symfony/clock: ^7.0`. Keep sort-packages order. The repo tracks no composer.lock; run `composer update` locally so vendor resolves.

Test scenarios (tests/PlatformMaintenanceTest.php, Kiln's 11 unit cases with a MockClock bound as `ClockInterface` and `Log::spy()`): inactive by default; start activates; same id is a noop and keeps the original expires_at; a different id replaces and answers activated; end with the active id deactivates; end with another id is a noop and the window stays; end while inactive is a noop; after max_minutes isActive() is false, forgets the key and logs exactly one `platform_maintenance.expired` warning; max_minutes comes from `allsystems.max_minutes`; the flag lives in the default store under `allsystems:platform-maintenance`; `app.maintenance.store = database` is ignored (window still works, nothing in the database store).
ServiceProviderTest additions: ClockInterface resolves to SystemClock when unbound; an app binding of ClockInterface made before the provider registers (bind in `defineEnvironment` / a test-specific provider) wins.

ACs met: window stored under allsystems.cache_key in `$cache->store()`, never MaintenanceMode; start/noop/replace semantics; end/noop semantics; expiry after max_minutes with the single expired warning; start/end info logs.

## Chunk 2: The signed webhook

Files:
- Modify: src/Http/Middleware/VerifySignature.php
- Modify: src/Http/Controllers/WebhookController.php
- Modify: routes/webhook.php
- Modify: src/AllSystemsServiceProvider.php
- Modify: config/allsystems.php
- Delete: src/Listeners/SyncMaintenanceMode.php
- Delete: src/SyncResult.php
- Delete: src/MaintenancePayload.php
- Delete: src/Events/MaintenanceStarted.php
- Delete: src/Events/MaintenanceEnded.php
- Delete: src/Events/PingReceived.php
- Delete: tests/SyncMaintenanceModeTest.php
- Delete: tests/MaintenancePayloadTest.php
- Delete: tests/WebhookControllerTest.php
- Delete: tests/Doubles/FakeMaintenanceMode.php
- Delete: tests/views/maintenance-window.blade.php
- Test: tests/WebhookTest.php
- Test: tests/VerifySignatureTest.php
- Test: tests/ServiceProviderTest.php
- Test: tests/TestCase.php

Interfaces: consumes PlatformMaintenance and ClockInterface from chunk 1. Hands chunk 3 the final flat config (adds `path`, `domain`) and a provider with `loadViewsFrom` still to add.

Behaviour:
- VerifySignature: body replaced with Kiln's VerifyAllSystemsSignature.php (handle, parse, reject). Keep the class name `VerifySignature` and `public const HEADER = 'X-AllSystems-Signature'`. Constructor (ConfigRepository, ClockInterface, LoggerInterface); secret from `allsystems.secret`, tolerance from `allsystems.tolerance`; a non-string or empty secret rejects `secret_unset`. reject() logs warning `allsystems.webhook_rejected` with only `['reason' => ...]` and answers 401 `{"error":"Invalid signature"}`. Make the class `final` (not readonly, or readonly with promoted readonly props; either passes on 8.2). phpstan level 9: `(string) $config->get(...)` on mixed is an error, so narrow with `is_string` / `is_numeric` as the current file does.
- WebhookController: Kiln's controller :33-67 and `answer()` :125-128 without the vehicle-lookup branch, VehicleLookupNotice, startNotice, isVehicleLookupWindow, parseTime. Constructor takes only PlatformMaintenance. Decode `getContent()`; not an array or no string `event` → 400 `{"error":"Invalid payload"}`; `ping` → noop; anything other than maintenance.started / maintenance.ended → ignored; `id` not a non-empty string → 400; ended → `end($id)`; started → `start($id)`. Every answer `new JsonResponse(['action' => $a], 200)`. The X-AllSystems-Event header is never read. A JSON list body (`[]`) decodes to an array without `event`, so it is a 400.
- routes/webhook.php: the route as today, wrapped in `Route::domain($domain)->group(...)` when `config('allsystems.domain')` is a non-empty string; otherwise unwrapped. Keep the "deliberately naked" comment.
- Provider: remove the SyncResult binding, the listener registration, the `allsystems.maintenance.enabled` read and the now-unused imports (Dispatcher, events, listener); boot() no longer takes Dispatcher. Path fallback literal becomes `webhooks/allsystems`. Keep the path normalisation and `PreventRequestsDuringMaintenance::except([$path])` with its comment updated (the gate is now PlatformMaintenanceMiddleware; the exemption remains for a hand-run `artisan down`).
- config/allsystems.php: remove the `maintenance` array; `path` default becomes `webhooks/allsystems`; add `domain => env('ALLSYSTEMS_WEBHOOK_DOMAIN')` (null means every host). Keep `secret` and `tolerance`.
- TestCase: bind a `Symfony\Component\Clock\MockClock` as `ClockInterface` (fixed instant) in defineEnvironment so signing tests use `$clock->now()->getTimestamp()` rather than `time()`; expose it to subclasses.

Test scenarios:
- tests/WebhookTest.php (Kiln AllSystemsWebhookTest.php :35-45 and :144-294, generic only): started opens the window and a redelivery answers noop; ended for the open window deactivates; ended for another window is noop and the window stays; the event comes from the body (header says ended, body says started → activated); rejected signatures as a data provider (missing header, malformed header, wrong secret, tampered body, stale and future timestamp) each 401 `{"error":"Invalid signature"}` and leave the window untouched; an unset or empty secret 401s even a valid signature; the tolerance edge (now−300 passes, now−301 fails); ping answers noop; an unknown event answers ignored; invalid bodies as a data provider (not JSON, a JSON scalar, a list, no event, non-string event, started without id, ended with empty id) answer 400 `{"error":"Invalid payload"}`; the golden delivery (vector 1 body with `t=1787000000` and the MockClock at that instant) answers activated.
- WebhookTest also: a 401 logs exactly one `allsystems.webhook_rejected` warning whose context is only `['reason' => <reason>]` (assert each of the four reasons); with `allsystems.domain = 'hooks.example.test'` the signed POST to `http://hooks.example.test/webhooks/allsystems` answers 200 and the same POST to another host answers 404.
- tests/VerifySignatureTest.php: kept; `PATH` becomes `/webhooks/allsystems`; `time()` replaced by the MockClock; rejection assertions expect `{"error":"Invalid signature"}`; the success cases use a body with `"event":"ping"` (already so) so 200 still holds.
- tests/ServiceProviderTest.php: drop listener and `maintenance.enabled` assertions; config-defaults test asserts `path` `webhooks/allsystems`, `tolerance` 300, `cache_key`, `max_minutes` 30, `retry_after`/`except`/`view`/`json_detail` arrive in chunk 3; path override, empty/wildcard fallback and exactly-one-exemption cases kept with the new default path, and their signed requests use a ping body (`[]` now answers 400); the `artisan down` exemption case still proves the webhook answers while Laravel's MaintenanceMode is active and `/` 503s.
- tests/SignatureVectorsTest.php: unchanged (it never calls `time()`); confirm it still passes.

ACs met: signed POST on path/domain with tolerance; 401 cases, logging and empty-secret rule; event from the body and 400 payload rules; started/ended/ping/ignored answers; webhook path still in PreventRequestsDuringMaintenance::except().

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

## Notes

- The release (rc then promote to v0.1.0) and the post-release checks are post-merge, Josh-run: no chunk.
