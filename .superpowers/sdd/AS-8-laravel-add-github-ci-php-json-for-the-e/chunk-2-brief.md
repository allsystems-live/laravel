# Chunk 2 brief: AS-8

## Global Constraints (from the plan)

- All repo gates green before the PR: `bash scripts/run-gates.sh --repo laravel AS-8-laravel-add-github-ci-php-json-for-the-e` from the orchestrator root (pint-test, analyse, phpunit).
- No weakened invariant, no auto-merge.
- The change is exactly one new file, `.github/ci/php.json`. Do not touch `.github/workflows/ci.yml` (its PHP 8.2/8.3/8.4 matrix stays as the coverage for versions the enterprise workflow, pinned to 8.5, does not run), `composer.json`, `composer.lock`, `phpunit.xml`, `phpstan.neon` or any source/test file.
- Do not create `.github/ci/js.json`; Josh sets `enable-js-workflows=false` on the repo himself.
- No coverage gate (`coverage.min` is opt-in and the ticket does not ask for it), no `shipquickly` (no private dependencies), no `version` override: the file carries only what differs from the ci-workflows template default.


## Chunk 2: verify on PHP 8.5 and confirm scope

**Files**
- Test: .github/ci/php.json (re-checked as part of the full gate run; no further file changes expected)

**Interfaces**
- Consumes: chunk 1's file.
- Hands: a branch ready for the PR, whose PHP check is the final AC.

**Behaviour**
- Run the exact commands the enterprise workflow will run, on local PHP 8.5: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/phpunit`, plus the orchestrator gates. Confirm `composer check-platform-reqs --lock` passes on 8.5 (the lock resolves Laravel 13 / testbench 11, which php.yml's `composer install` will use).
- Confirm scope: `git diff --name-only origin/main` lists only `.github/ci/php.json`; `.github/workflows/ci.yml` is byte-identical to main; no `.github/ci/js.json` exists.
- If any tool fails on 8.5, stop and report rather than editing source or relaxing a gate; the ticket is config-only.

**Test scenarios**
- Pint, PHPStan and PHPUnit all pass on PHP 8.5 (pre-checked by the planner: pint ok, phpstan ok, 58 tests / 159 assertions OK on PHP 8.5.11).
- Full gate run green: `bash scripts/run-gates.sh --repo laravel AS-8-laravel-add-github-ci-php-json-for-the-e`.
- Diff scope check returns exactly one path.

**ACs met**
- AC3 (ci.yml unchanged).
- AC4 (PHP green on the PR) is proven by the PR's PHP check run once the PR is open; the local 8.5 run is its precondition.


## Touched-test command

From the worktree: `vendor/bin/pint --test && vendor/bin/phpstan analyse && vendor/bin/phpunit` and `composer check-platform-reqs --lock`; from the orchestrator root: `bash scripts/run-gates.sh --repo laravel AS-8-laravel-add-github-ci-php-json-for-the-e`.

## Report

Write to `/Users/joshmurray/dev/agent-farm/laravel-AS-8-laravel-add-github-ci-php-json-for-the-e/.superpowers/sdd/AS-8-laravel-add-github-ci-php-json-for-the-e/chunk-2-report.md`; commit it with `git add -f` and push. No other file changes are expected.
