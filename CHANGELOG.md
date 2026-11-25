# Changelog

All notable changes to `allsystems/laravel` are documented here.

## v0.2.0

- Optional `MaintenanceInterceptor`: an app can claim a `maintenance.started` or `maintenance.ended` before the platform window changes.
- Nothing changes when no interceptor is bound.

## v0.1.0

- Signed webhook at `POST {allsystems.path}` (default `webhooks/allsystems`, optionally restricted to `allsystems.domain`) with no CSRF, session or auth middleware: the HMAC signature is the authentication.
- `VerifySignature` middleware: parses `t=<unix>,v1=<hex>`, enforces a 300-second replay tolerance and compares digests with `hash_equals()`; failures answer `401 {"error":"Invalid signature"}`.
- `PlatformMaintenance`: the maintenance window is a flag in the default cache store keyed by window id, expiring after `allsystems.max_minutes`, so no database is touched. Webhook answers are `{"action":"activated|deactivated|noop|ignored"}`.
- `PlatformMaintenanceMiddleware` for the global stack: 503 with `Retry-After` and `Cache-Control: no-store` while a window is open, a JSON:API body for JSON requests, and a bundled self-contained page (`allsystems::maintenance`) for HTML. Exempts `up`, the webhook path and `allsystems.except`.
- The webhook path is exempted from Laravel's `PreventRequestsDuringMaintenance`, so `ended` still lands after a hand-run `artisan down`.
- `config/allsystems.php`: `secret`, `path`, `domain`, `tolerance`, `cache_key`, `max_minutes`, `retry_after`, `except`, `view`, `json_detail`.
- CI matrix across PHP 8.2-8.4 and Laravel 12-13 (5 cells; PHP 8.2 x Laravel 13 excluded because `orchestra/testbench` ^11 requires PHP 8.3 or later). Laravel 11 is unsupported: every `laravel/framework` 11.x release is blocked by Composer's default security-advisory check.
- README and `docs/webhook-contract.md` document the contract, including a golden HMAC test vector for non-Laravel receivers.
