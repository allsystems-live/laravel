Chunk: 1
Status: complete
Started: 2026-11-23T23:46:00Z

Project skills: none

Built: PlatformMaintenance (ported from Kiln, config-driven key and max_minutes), SystemClock, ClockInterface bindIf in the provider, config keys cache_key and max_minutes, composer requires (illuminate/cache, illuminate/view, psr/clock, psr/log; dev symfony/clock). composer update run locally.

Tests: `vendor/bin/phpunit tests/PlatformMaintenanceTest.php tests/ServiceProviderTest.php` -> OK (20 tests); `composer check` -> pint, phpstan, 71 tests green.

Prove-fail: replaced bindIf with bind in the provider; testAnAppBindingOfTheClockWins failed, restored, green.

Files: src/PlatformMaintenance.php, src/SystemClock.php, src/AllSystemsServiceProvider.php, config/allsystems.php, composer.json, tests/PlatformMaintenanceTest.php, tests/ServiceProviderTest.php, tests/Doubles/MockClockProvider.php (new).

Deviations: state() validates the cached shape field by field instead of Kiln's @var (pint rewrites the docblock and phpstan 9 rejects the array); maxMinutes falls back to 30 on a non-numeric value. The "app binding wins" test uses a test provider registered before the package provider because Testbench's defineEnvironment runs after providers register, making an instance() there vacuous. Gates via run-gates.sh not run; composer check green.
