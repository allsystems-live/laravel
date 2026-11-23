Review: branch
Status: complete
Started: 2026-11-23T23:51:56Z
Finished: 2026-11-23T23:55:21Z

REVIEW AS-385-allsystems-laravel-takes-kiln-s-receiver BRANCH: CHANGES REQUIRED
Findings:
  [IMPORTANT-1] [test] testAnAppBindingOfTheClockWins passes even when bindIf is replaced by bind, so the "app binding wins" behaviour has no working test
    Where: tests/ServiceProviderTest.php:43-52, tests/ServiceProviderTest.php:81; tests/TestCase.php:47-51
    Why: Ticket Testing: "ClockInterface resolves ... to the app's binding when bound". Rule 5 says every behaviour change needs a test that fails when the behaviour regresses. mocksTheClock() returns true for this test, so TestCase::defineEnvironment runs $app->instance(ClockInterface::class, $this->clock) after the provider registers. That instance overrides whatever the provider bound, so MockClockProvider and the bindIf have no effect on the result. The comment at :44 ("Registers first ... so its binding is already in place") describes a mechanism the test does not depend on.
    Proof: I ran this in a scratch copy at $CLAUDE_JOB_DIR/tmp/as385, not in the build worktree.
      sed -i '' 's/bindIf(ClockInterface/bind(ClockInterface/' src/AllSystemsServiceProvider.php; vendor/bin/phpunit tests/ServiceProviderTest.php
      -> OK (10 tests, 24 assertions)   (regression not caught)
      I then changed mocksTheClock() to also return false for testAnAppBindingOfTheClockWins:
      with bindIf -> OK (10 tests, 24 assertions)
      with bind   -> 1) ServiceProviderTest::testAnAppBindingOfTheClockWins  Failed asserting that an instance of class AllSystems\Laravel\SystemClock is an instance of class Symfony\Component\Clock\MockClock.
    Fix: Make mocksTheClock() in ServiceProviderTest return false for testAnAppBindingOfTheClockWins as well as testTheClockDefaultsToTheSystemClock. Then MockClockProvider is the only source of the binding, and the test fails when bindIf becomes bind.
  [MINOR-1] [test] A custom allsystems.cache_key is never tested. Hardcoding the key leaves the suite green.
    Where: src/PlatformMaintenance.php:116-121; tests/PlatformMaintenanceTest.php
    Why: AC: "The window is stored under allsystems.cache_key (default allsystems:platform-maintenance)". Only the default is exercised (testTheFlagLivesInTheDefaultStore), so an override is untested (rule 5).
    Proof: In the scratch copy I made key() always return self::DEFAULT_KEY, then ran vendor/bin/phpunit -> OK (74 tests, 208 assertions).
    Fix: In PlatformMaintenanceTest, set allsystems.cache_key to a custom value, start a window, and assert that Cache::store() holds the state under the custom key and not under the default one.
  [MINOR-2] [test] One config-defaults test and one structural view-registration test
    Where: tests/ServiceProviderTest.php:86-96 (six new assertions on config() values), tests/ServiceProviderTest.php:98-101 (view()->exists)
    Why: Quality rule: no config or structural tests. Behaviour tests already cover each default: Retry-After 60 and json_detail in PlatformMaintenanceMiddlewareTest; the up exemption and the bundled view in testUpIsNotAnsweredByTheGate and testAnOpenWindowAnswersTheHtmlPage; max_minutes 30 in testTheWindowExpiresAfterMaxMinutesWithOneWarning; cache_key in testTheFlagLivesInTheDefaultStore; path and tolerance in WebhookTest. The plan asked for these tests, so this rule break comes from the plan.
    Proof: quoted: "self::assertSame(60, config('allsystems.retry_after')); ... self::assertTrue(view()->exists('allsystems::maintenance'));"
    Fix: Drop the six assertions added to testConfigMergesWithTheDocumentedDefaults, and drop testTheBundledMaintenanceViewIsRegistered.
  [MINOR-3] [comment] The new exemption comment is three lines and restates what the code does
    Where: src/AllSystemsServiceProvider.php:44-46
    Why: Comment rule: why-only, 1-2 lines.
    Proof: quoted: "// PreventRequestsDuringMaintenance is in Laravel's GLOBAL middleware stack, so after a hand-run / // `artisan down` the `maintenance.ended` webhook would 503 too. The platform window is gated by / // PlatformMaintenanceMiddleware instead, but this exemption keeps the webhook reachable either way."
    Fix: Cut it to one line, for example "// Keeps the webhook reachable after a hand-run `artisan down`, so `ended` still lands."
  [MINOR-4] [correctness] illuminate/cache is listed after illuminate/config, but composer.json sets sort-packages: true
    Where: composer.json:9-10
    Why: The plan says "Keep sort-packages order". The next `composer require` will reorder these lines and leave an unrelated diff.
    Proof: quoted: "\"illuminate/config\": \"^12.0 || ^13.0\",\n        \"illuminate/cache\": \"^12.0 || ^13.0\","
    Fix: Move illuminate/cache above illuminate/config.
Verdict rationale: The port matches Kiln and the ticket. All ACs are implemented. Mutations to the store, tolerance, no-store and expiry code are each caught by the suite. No PHP 8.3+ syntax was found. The release note is within its rules. One test the ticket requires (the app's clock binding wins) is vacuous, so the branch needs one fix wave.
