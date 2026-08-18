# Changelog

All notable changes to `allsystems/laravel` are documented here.

## Unreleased

Initial feature set:

- Service provider registering `POST {config path}` (default `allsystems/webhook`) with no CSRF, no session and no auth middleware — the HMAC signature is the authentication.
- `VerifySignature` middleware: parses the `t=<unix>,v1=<hex>` signature header, enforces a 300-second replay tolerance, and compares digests with `hash_equals()`.
- `config/allsystems.php`: `secret`, `path`, `tolerance`, and a `maintenance.*` block (`enabled`, `secret`, `template`, `redirect`, `refresh`) that maps straight onto Laravel's own maintenance-mode options.
- `MaintenanceStarted`, `MaintenanceEnded` and `PingReceived` events, dispatched from a single webhook controller that returns `{ ok, action }` for any request it understands and a `noop` `200` for anything it does not.
- `SyncMaintenanceMode` listener, registered by default: activates Laravel's `MaintenanceMode` on `maintenance.started` and deactivates it on `maintenance.ended` — but only when the currently-active maintenance mode carries the id of the window being ended, so a hand-run `artisan down` or a different window's maintenance is never lifted by AllSystems.
- Automatic exemption of the configured webhook path from `PreventRequestsDuringMaintenance`, so the `maintenance.ended` webhook that is supposed to lift maintenance mode is never itself blocked by it.
- CI matrix across PHP 8.2–8.4 and Laravel 12–13 (5 cells; PHP 8.2 × Laravel 13 excluded — `orchestra/testbench` ^11 requires PHP ≥ 8.3). Laravel 11 was dropped from the supported range: every `laravel/framework` 11.x release is permanently blocked by Composer's default security-advisory check (no fix was ever backported to the EOL 11.x line), so `orchestra/testbench ^9.0` could never resolve — see README's Requirements section.
- README documenting the full webhook contract, including a golden HMAC test vector, for non-Laravel receivers.
