# AS-403: allsystems/laravel: apps can intercept a window; v0.2.0
Ticket: AS-403 @ 2026-11-24T10:33:01.799+0000

## Global Constraints

- All gates green before the PR, run from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-403-allsystems-laravel-apps-can-intercept-a` (gates: `pint-test`, `analyse`, `phpunit`; one at a time with `--only pint|analyse|test`). This is `composer check` (pint --test, phpstan, phpunit); CI repeats it across the PHP 8.2-8.4 x Laravel 12-13 matrix.
- No weakened invariant: phpstan level and baseline untouched, no `@phpstan-ignore`, no test skipped or loosened. Every existing case in `tests/WebhookTest.php` stays byte-for-byte unchanged (AC 2 is proven by it passing with nothing bound).
- No auto-merge. No tag or release: Josh cuts v0.2.0 after merge (`gh release-train rc -R allsystems-live/laravel minor`, then `promote`).
- Does not touch: `VerifySignature`, `PlatformMaintenance`, `PlatformMaintenanceMiddleware`, `AllSystemsServiceProvider` (no default binding: the container falls back to the constructor default), `config/allsystems.php`, `routes/webhook.php`, the bundled view, `composer.json`, CI files.
- Project skills: none

## Chunk 1: the interceptor contract and the controller hook

**Files**
- Create: src/Contracts/MaintenanceInterceptor.php
- Modify: src/Http/Controllers/WebhookController.php
- Test: tests/MaintenanceInterceptorTest.php

**Interfaces**
- Hands later chunks: `AllSystems\Laravel\Contracts\MaintenanceInterceptor` with `started(string $windowId, array $payload): ?string` (docblock `@param array<string, mixed> $payload`, `@return 'activated'|'noop'|null`; null lets the platform window open) and `ended(string $windowId, array $payload): ?string` (`@return 'deactivated'|null`; null lets `PlatformMaintenance::end()` run). Interface docblock: an app binds it in a service provider's `register()` to claim a window before the platform window opens or closes.
- `WebhookController::__construct(private readonly PlatformMaintenance $maintenance, private readonly ?MaintenanceInterceptor $interceptor = null)`.

**Behaviour**
- Class docblock gains: "An app may bind MaintenanceInterceptor to claim a window before the platform window opens or closes."
- Nothing before the `$id` check changes, so ping, unknown events, 400s and (in the middleware) 401s never reach the interceptor.
- After the `$id` check, replace the single ternary with two branches: for `maintenance.ended`, `$action = $this->interceptor?->ended($id, $payload)`; answer `$action` when it is exactly `'deactivated'`, otherwise answer `$this->maintenance->end($id)`. For `maintenance.started`, `$action = $this->interceptor?->started($id, $payload)`; answer it when it is `'activated'` or `'noop'` (strict `in_array`), otherwise `$this->maintenance->start($id)`. Any other string is treated as null.
- `$payload` passed is the already-decoded array (`array<mixed>` from `json_decode`); if phpstan objects to passing it as `array<string, mixed>`, narrow with a `/** @var array<string, mixed> $payload */` on the decoded value rather than loosening the interface docblock.
- Nothing is bound by the provider. Laravel's container, on `BindingResolutionException` for an unbound interface parameter that has a default value, passes the default (`Container::resolveClass()`), so `null` arrives when no app binds the interface. A test in this chunk proves it.

**Test scenarios** (new `tests/MaintenanceInterceptorTest.php`, extends `TestCase`)
- A recording test double, an anonymous class built in `defineEnvironment()` and implementing the interface: per-test configurable returns for `started()`/`ended()`, records every call as `[method, windowId, payload]`. Bind it with `$app->instance(MaintenanceInterceptor::class, $double)` in `defineEnvironment()`.
- Copy the four private helpers (`maintenance()`, `body()`, `header()`, `deliver()`/`send()`) from `tests/WebhookTest.php` into the new test; do not extract them from `WebhookTest.php` (its cases stay unchanged).
- `testStartedClaimedAsActivatedLeavesThePlatformWindowShut`: answer `activated`, `isActive()` false, the double recorded `started` with the id and the decoded body (assert the full array, using a body that carries `components`).
- `testStartedClaimedAsNoopLeavesThePlatformWindowShut`: answer `noop`, `isActive()` false.
- `testStartedNotClaimedOpensThePlatformWindow`: double returns null; answer `activated`, `isActive()` true.
- `testEndedClaimedLeavesTheOpenWindowOpen`: open window-1 with started (double returns null), then ended window-1 with double returning `deactivated`; answer `deactivated`, `isActive()` still true, double recorded `ended` with the id and body.
- `testEndedNotClaimedClosesTheOpenWindow`: same setup, ended returns null; answer `deactivated`, `isActive()` false.
- `testOutOfContractReturnsAreTreatedAsNull` (data provider): `started()` returning `'deactivated'` (and `'ignored'`) opens the window and answers `activated`; `ended()` returning `'noop'` closes the open window and answers `deactivated`.
- `testTheInterceptorIsNotCalledFor` (data provider): ping, unknown event, bad signature (wrong v1), invalid body (non-JSON, and a started body with an empty id); the double records no call.
- `testUnboundInterceptorResolvesToNull`: in a test that does not bind (gate the binding in `defineEnvironment()` on `$this->name()`, as `WebhookTest` does), `app(WebhookController::class)` resolves and a started delivery answers `activated` with the window open.

**ACs met**: 1, 2 (with the unchanged `WebhookTest.php`), 3, 4, 5, 6, 8 (for the code).

## Chunk 2: docs and changelog for v0.2.0

**Files**
- Modify: README.md (new "Intercepting a window" section after Install; require line in Install bumped to `^0.2.0` so the documented feature is installable)
- Modify: docs/webhook-contract.md (processing order under "The webhook contract")
- Modify: CHANGELOG.md (a `## v0.2.0` section above v0.1.0)

**Interfaces**
- Consumes the interface and controller behaviour from chunk 1; hands nothing on.

**Behaviour**
- README "Intercepting a window" (between `## Install` and `## Run it`): what the interface is for (an app claims a window before the platform window changes), when each method runs (`started()` on a signed, valid `maintenance.started`; `ended()` on a signed, valid `maintenance.ended`; never for ping, unknown events, 401 or 400), what each return means (`activated`/`noop` from `started()` and `deactivated` from `ended()` become the webhook answer and the platform window is left alone; null, or any other value, falls through to the v0.1.0 behaviour), and the binding: `$this->app->bind(MaintenanceInterceptor::class, YourInterceptor::class);` in a service provider's `register()`, with the `use AllSystems\Laravel\Contracts\MaintenanceInterceptor;` line.
- docs/webhook-contract.md, inside "The webhook contract" (after the Responses paragraph, before Idempotency): a short "Processing order" paragraph or list: signature (401), body (400; ping answers noop, unknown events ignored), the app's `MaintenanceInterceptor` if one is bound, then the platform window. State that a claimed window does not touch the platform window, and that receivers in other languages can ignore the step.
- CHANGELOG: `## v0.2.0` with "Optional `MaintenanceInterceptor`: an app can claim a `maintenance.started` or `maintenance.ended` before the platform window changes." plus one line that nothing changes when it is not bound.
- Markdown: one line per paragraph, no hard wrapping.

**Test scenarios**
- None beyond the full gate run (`bash scripts/run-gates.sh --repo laravel AS-403-allsystems-laravel-apps-can-intercept-a`), which must be green after this chunk.

**ACs met**: 7, 8.

## Notes

- Simplest approach: an optional nullable constructor argument resolved by the container, no provider binding, no config key (the ticket rejects both alternatives).
