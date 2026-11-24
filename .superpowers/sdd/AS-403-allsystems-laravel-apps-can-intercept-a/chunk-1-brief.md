# Chunk 1 brief: AS-403

Project skills: none

Touched-test command (from the worktree root): `vendor/bin/phpunit tests/MaintenanceInterceptorTest.php tests/WebhookTest.php`, plus `vendor/bin/pint --test` and `composer analyse` on what you touch.

Report path: /Users/joshmurray/dev/agent-farm/laravel-AS-403-allsystems-laravel-apps-can-intercept-a/.superpowers/sdd/AS-403-allsystems-laravel-apps-can-intercept-a/chunk-1-report.md (commit it with `git add -f` alongside the code).

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

