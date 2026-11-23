# Chunk 1 brief: AS-385

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


## Touched-test command

In the worktree: `vendor/bin/phpunit tests/PlatformMaintenanceTest.php tests/ServiceProviderTest.php`; full suite `composer check`. Gates from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-385-allsystems-laravel-takes-kiln-s-receiver` (`--only <gate>` while iterating).

## Report

.superpowers/sdd/AS-385-allsystems-laravel-takes-kiln-s-receiver/chunk-1-report.md (commit it with git add -f)
