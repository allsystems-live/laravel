# Chunk 2 brief: AS-403

Project skills: none

Touched-test command (from the worktree root): `vendor/bin/pint --test && composer analyse && vendor/bin/phpunit` (docs only, so confirm nothing else moved).

Report path: /Users/joshmurray/dev/agent-farm/laravel-AS-403-allsystems-laravel-apps-can-intercept-a/.superpowers/sdd/AS-403-allsystems-laravel-apps-can-intercept-a/chunk-2-report.md (commit it with `git add -f` alongside the docs).

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

