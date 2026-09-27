# allsystems-live/laravel

Composer package: signed AllSystems maintenance webhooks driving Laravel's own maintenance mode. No deploy.

## Commands

```bash
composer install
composer test        # vendor/bin/phpunit
composer check        # pint --test, phpstan analyse, phpunit — all green before any PR
```

## Branch and PR rules

- Since 2026-09-26 the enterprise ruleset "Default branch protection" applies to every org in the enterprise, requiring a pull request into `main` and a squash merge.
- Branch off `main`: `build/*` (the delivery pipeline's own branches), or hand-cut `feat/*`, `fix/*`, `docs/*`, `chore/*`.
- PRs are bot-authored, request `joshmurrayeu` as reviewer, never auto-merged by the bot.
- Squash merge only, enforced by the ruleset above.
- Commit bodies: one line per paragraph, no manual wrapping.

Every repo README follows the estate standard: https://github.com/JMWD-Platform/infrastructure/blob/main/docs/standards/readme.md
