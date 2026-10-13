# AS-8: laravel: add .github/ci/php.json for the enterprise PHP workflow
Ticket: AS-8 @ 2026-10-12T23:55:27.039+0100

## Global Constraints

- All repo gates green before the PR: `bash scripts/run-gates.sh --repo laravel AS-8-laravel-add-github-ci-php-json-for-the-e` from the orchestrator root (pint-test, analyse, phpunit).
- No weakened invariant, no auto-merge.
- The change is exactly one new file, `.github/ci/php.json`. Do not touch `.github/workflows/ci.yml` (its PHP 8.2/8.3/8.4 matrix stays as the coverage for versions the enterprise workflow, pinned to 8.5, does not run), `composer.json`, `composer.lock`, `phpunit.xml`, `phpstan.neon` or any source/test file.
- Do not create `.github/ci/js.json`; Josh sets `enable-js-workflows=false` on the repo himself.
- No coverage gate (`coverage.min` is opt-in and the ticket does not ask for it), no `shipquickly` (no private dependencies), no `version` override: the file carries only what differs from the ci-workflows template default.

## Chunk 1: add the PHP workflow config

**Files**
- Create: .github/ci/php.json (the per-repo config the enterprise `php.yml` required workflow reads; without it the run fails)

**Interfaces**
- Consumes: the template default in JMWD-Platform/ci-workflows `.github/templates/ci/php.json` (PHP 8.5, Postgres 17 service, static commands `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`, tests `vendor/bin/phpunit` in a single `all` shard). php.yml deep-merges the repo file over it: objects merge, arrays and scalars replace, `false` disables a section.
- Hands chunk 2: the committed file.

**Behaviour**
- File content is exactly the ticket's JSON, pretty-printed to match the repo's JSON style (4-space indent, trailing newline), e.g. `{"services": {"postgres": false}}` spread over lines. Only the Postgres service is disabled: the package has no database (phpunit.xml uses the file maintenance driver, testbench supplies the app). Everything else inherits the template: PHP 8.5, Pint and PHPStan static commands, PHPUnit single shard.
- The default PHPStan command has no `--memory-limit`; php.yml sets PHP's `memory-limit` to 1G via the template, so no override is needed (verified: `vendor/bin/phpstan analyse` passes locally without the flag).

**Test scenarios**
- `jq -e '.services.postgres == false and (keys == ["services"])' .github/ci/php.json` succeeds.
- The file validates against ci-workflows' `.github/schemas/php-ci.schema.json` (if a validator is handy; otherwise the jq check above plus the shape match against ci-workflows' `tests/fixtures/php-ci-config/good-package.json` is enough).

**ACs met**
- AC1 (php.json with postgres disabled, default static commands and single shard, no private dependencies).
- AC2 (no js.json).

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

## Notes

- The PHP check only appears on the PR if the php.yml ruleset is active or the workflow is otherwise triggered for this repo; the ticket says this PR must merge before Josh activates the ruleset, so the manager should record in the PR body whether the PHP check ran, and if not, the local 8.5 evidence.
