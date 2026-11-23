Chunk: 3
Status: complete
Started: 2026-11-23T23:49:50Z

Project skills: none

## Built
PlatformMaintenanceMiddleware (exempts webhook path + allsystems.except before reading the cache; 503 with Retry-After/no-store; JSON:API body for expectsJson; else the configured view), bundled allsystems::maintenance view, provider loadViewsFrom, four new config keys (ten flat keys total), composer description, README (What it is, Install, release-train commands, Layout), webhook-contract (The window, ten keys, response bodies), CHANGELOG v0.1.0, CLAUDE.md line.

## Tests
- vendor/bin/phpunit tests/PlatformMaintenanceMiddlewareTest.php tests/ServiceProviderTest.php -> OK
- composer-equivalent gates via run-gates.sh (pint-test, analyse, phpunit) -> GREEN, 74 tests
Prove-fail: replaced the gate condition with always-pass: 6 of 9 middleware tests failed; removing only the exempt-path check failed up, ended-webhook and except tests. Restored, green.

## Files
src/Http/Middleware/PlatformMaintenanceMiddleware.php, resources/views/maintenance.blade.php, src/AllSystemsServiceProvider.php, config/allsystems.php, composer.json, README.md, docs/webhook-contract.md, CHANGELOG.md, CLAUDE.md, tests/PlatformMaintenanceMiddlewareTest.php, tests/ServiceProviderTest.php, tests/views/custom-outage.blade.php (fixture for the view override test).

## Discrepancies
- Brief's Kiln path is wrong: the middleware is at application/app/Miscellaneous/Http/Middleware/PlatformMaintenanceMiddleware.php on origin/main.
- Kiln's class uses a PlatformMaintenance::RETRY_AFTER_SECONDS constant; ported to config.
- Contract doc: dropped the "moved during README rewrite" preamble line (stale).
- No upstream workarounds.
