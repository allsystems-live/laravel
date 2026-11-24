Chunk: 1
Status: complete
Started: 

Project skills: none

## Built
- src/Contracts/MaintenanceInterceptor.php: started()/ended() contract.
- WebhookController: optional nullable interceptor; started answers it on 'activated'/'noop', ended on 'deactivated', else platform start()/end(). Added a @var array<string, mixed> on the decoded payload (phpstan clean).
- tests/MaintenanceInterceptorTest.php: all brief scenarios (14 cases incl. providers, unbound-resolves-null).

## Tests
- vendor/bin/phpunit tests/MaintenanceInterceptorTest.php tests/WebhookTest.php -> OK (42 tests, 118 assertions)
- vendor/bin/pint --test -> passed; composer analyse -> no errors

## Prove-fail
Reverted WebhookController: new test file -> 3 failures (claimed started/ended cases); reapplied -> 14 green.

## Deviations
- The recording double is a named final class RecordingInterceptor in the test file, not an anonymous class: phpstan rejects property access on an anonymous object-typed property. Its properties/returns carry a widened docblock so out-of-contract returns can be tested.

## Files
src/Contracts/MaintenanceInterceptor.php, src/Http/Controllers/WebhookController.php, tests/MaintenanceInterceptorTest.php, this report.
