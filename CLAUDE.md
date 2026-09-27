# allsystems-live/laravel

Composer package: signed AllSystems maintenance webhooks driving Laravel's own maintenance mode. No deploy.

## Commands

```bash
composer install
composer test        # vendor/bin/phpunit
composer check        # pint --test, phpstan analyse, phpunit — all green before any PR
```

## Branch and PR rules

- Branch off `main`: `feat/*`, `fix/*`, `docs/*`.
- PRs are bot-authored, request `joshmurrayeu` as reviewer, never auto-merged by the bot.
- Squash merge only.
- Commit bodies: one line per paragraph, no manual wrapping.

Every repo README follows the estate standard: https://github.com/JMWD-Platform/infrastructure/blob/main/docs/standards/readme.md
