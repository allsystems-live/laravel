# Chunk 2 brief: AS-385

- Project skills: none

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


## Touched-test command

In the worktree: `vendor/bin/phpunit tests/WebhookTest.php tests/VerifySignatureTest.php tests/SignatureVectorsTest.php tests/ServiceProviderTest.php`; full suite `composer check`. Gates from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-385-allsystems-laravel-takes-kiln-s-receiver` (`--only <gate>` while iterating).

## Report

.superpowers/sdd/AS-385-allsystems-laravel-takes-kiln-s-receiver/chunk-2-report.md (commit it with git add -f)
