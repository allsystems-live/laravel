Chunk: fix-wave
Status: complete
Started: 2026-11-23T23:55:52Z

Project skills: none

Built: IMPORTANT-1 mocksTheClock() now false for testAnAppBindingOfTheClockWins too; MINOR-1 added testTheFlagLivesUnderTheConfiguredCacheKey; MINOR-2 dropped config-default and bundled-view tests; MINOR-3 exemption comment cut to one line; MINOR-4 illuminate/cache sorted above config.

Tests: `vendor/bin/phpunit tests/ServiceProviderTest.php tests/PlatformMaintenanceTest.php`, `composer check`, `scripts/run-gates.sh --repo laravel AS-385-...` -> all green (73 tests).

Prove-fail: bindIf->bind in the provider fails testAnAppBindingOfTheClockWins; key() returning the default fails testTheFlagLivesUnderTheConfiguredCacheKey; both restored (git diff src shows no bind change).

Files: composer.json, src/AllSystemsServiceProvider.php, tests/ServiceProviderTest.php, tests/PlatformMaintenanceTest.php
Unrequested changes: none. Plan discrepancies: none.
