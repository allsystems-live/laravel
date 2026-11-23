Chunk: 2
Status: complete
Started: 2026-11-23T23:47:39Z

Project skills: none

## Built
Signed webhook per brief: VerifySignature (Kiln body, reasons logged, 401 {"error":"Invalid signature"}), WebhookController (PlatformMaintenance only, 400/noop/ignored/activated/deactivated), domain-wrapped route, provider/config slimmed (path webhooks/allsystems, domain added, maintenance array and listener/SyncResult removed), old events/listener/payload/tests deleted.

## Tests
- vendor/bin/phpunit tests/WebhookTest.php tests/VerifySignatureTest.php tests/SignatureVectorsTest.php tests/ServiceProviderTest.php -> OK
- bash scripts/run-gates.sh --repo laravel AS-385-... -> pint, phpstan, phpunit GREEN (64 tests, 175 assertions)

## Prove-fail
Replaced hash_equals(...) with true in VerifySignature: WebhookTest ran 28 tests, 3 failures (tampered body, wrong secret, mismatch log). Restored, green.

## Files
Modified: config/allsystems.php, routes/webhook.php, src/AllSystemsServiceProvider.php, src/Http/Controllers/WebhookController.php, src/Http/Middleware/VerifySignature.php, tests/TestCase.php, tests/VerifySignatureTest.php, tests/ServiceProviderTest.php, tests/PlatformMaintenanceTest.php. Created: tests/WebhookTest.php. Deleted: per brief list.

## Unrequested changes / discrepancies
- tests/PlatformMaintenanceTest.php (chunk 1): dropped its private $clock (phpstan: TestCase now declares a protected one); it still assigns its own MockClock to it.
- TestCase gains mocksTheClock() hook: Testbench defineEnvironment binds the MockClock after package registration, so testTheClockDefaultsToTheSystemClock opts out by name.
- Class kept non-readonly final (brief allowed). Provider comment mentions PlatformMaintenanceMiddleware, which chunk 3 adds.
- Kiln source read from a neighbouring farm clone (KILN-773 branch), not origin.
