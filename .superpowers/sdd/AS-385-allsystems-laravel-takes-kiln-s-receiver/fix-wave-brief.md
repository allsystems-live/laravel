# Fix wave brief: AS-385

- Project skills: none

Fix every finding below from the branch review (full text, proofs and suggested fixes: .superpowers/sdd/AS-385-allsystems-laravel-takes-kiln-s-receiver/review-branch.md). One commit is fine.

- IMPORTANT-1: tests/ServiceProviderTest.php testAnAppBindingOfTheClockWins is vacuous (passes with bindIf→bind). Make mocksTheClock() return false for it too, and prove it: swap bindIf→bind locally, see it fail, restore.
- MINOR-1: add a PlatformMaintenance test with a custom allsystems.cache_key: state stored under the custom key in Cache::store(), not under the default. Prove it fails if key() returns the default.
- MINOR-2: drop the six config-default assertions (ServiceProviderTest ~:86-96) and testTheBundledMaintenanceViewIsRegistered (~:98-101); behaviour tests already cover them.
- MINOR-3: cut the exemption comment in src/AllSystemsServiceProvider.php:44-46 to one why-line, e.g. "// Keeps the webhook reachable after a hand-run `artisan down`, so `ended` still lands."
- MINOR-4: composer.json: move illuminate/cache above illuminate/config (sort-packages).

## Touched-test command

In the worktree: `vendor/bin/phpunit tests/ServiceProviderTest.php tests/PlatformMaintenanceTest.php`; then `composer check`. Gates from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-385-allsystems-laravel-takes-kiln-s-receiver`.

## Report

.superpowers/sdd/AS-385-allsystems-laravel-takes-kiln-s-receiver/fix-wave-report.md (commit it with git add -f)
