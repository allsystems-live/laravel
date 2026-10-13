# Chunk 1 brief: AS-8

## Global Constraints (from the plan)

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


## Touched-test command

From the worktree: `vendor/bin/pint --test && vendor/bin/phpstan analyse && vendor/bin/phpunit` (or `composer check`), plus the jq check above.

## Report

Write to `/Users/joshmurray/dev/agent-farm/laravel-AS-8-laravel-add-github-ci-php-json-for-the-e/.superpowers/sdd/AS-8-laravel-add-github-ci-php-json-for-the-e/chunk-1-report.md`; commit it with `git add -f` alongside the file, and push.
