<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\Http\Middleware\VerifySignature;
use AllSystems\Laravel\Listeners\SyncMaintenanceMode;
use AllSystems\Laravel\MaintenancePayload;
use AllSystems\Laravel\SyncResult;
use AllSystems\Laravel\Tests\Doubles\FakeMaintenanceMode;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Factory as ViewFactory;

use function config;

/**
 * Drives SyncMaintenanceMode directly through the event dispatcher, with a
 * FakeMaintenanceMode standing in for the real driver, so every assertion is
 * about what the listener told MaintenanceMode to do — not about a file on
 * disk. The id-guard tests are the reason this package exists: see the class
 * docblock on SyncMaintenanceMode.
 */
final class SyncMaintenanceModeTest extends TestCase
{
    private const PATH = '/allsystems/webhook';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'testMaintenanceDisabledListenerIsNotRegisteredButEventsStillDispatchAndWebhookStillReturns200') {
            config(['allsystems.maintenance.enabled' => false]);
        }
    }

    protected function tearDown(): void
    {
        // The "end to end" test drives the real file-backed MaintenanceMode
        // to prove the B2 exemption; leaving it active would leak "down" into
        // every test that runs after it, in this class and beyond.
        $app = $this->app;

        if ($app !== null) {
            $maintenanceMode = $app->make(MaintenanceMode::class);

            if ($maintenanceMode->active()) {
                $maintenanceMode->deactivate();
            }
        }

        parent::tearDown();
    }

    public function testStartedColdActivatesWithTheDocumentedDefaultsAndSetsActionActivated(): void
    {
        $fake = $this->bindFake();
        $payload = $this->payload('window-1', (new DateTimeImmutable())->modify('+1800 seconds'));

        $this->dispatch(new MaintenanceStarted($payload));

        self::assertCount(1, $fake->activations);
        $activation = $fake->activations[0];

        self::assertSame(['allsystems/webhook'], $activation['except']);
        self::assertNull($activation['redirect']);
        self::assertIsInt($activation['retry']);
        self::assertGreaterThanOrEqual(1795, $activation['retry']);
        self::assertLessThanOrEqual(1800, $activation['retry']);
        self::assertNull($activation['refresh']);
        self::assertNull($activation['secret']);
        self::assertSame(503, $activation['status']);
        self::assertNull($activation['template']);
        self::assertSame('window-1', $activation[SyncMaintenanceMode::ID_KEY]);

        self::assertSame('activated', $this->syncResult()->action);
    }

    public function testStartedWithConfigSetPassesThroughSecretRedirectRefreshAndRendersTheTemplate(): void
    {
        config([
            'allsystems.maintenance.secret' => 'bypass-phrase',
            'allsystems.maintenance.redirect' => '/status',
            'allsystems.maintenance.refresh' => 30,
            'allsystems.maintenance.template' => 'maintenance-window',
        ]);

        $app = $this->app;
        assert($app !== null);
        $app->make(ViewFactory::class)->addLocation(__DIR__ . '/views');

        $fake = $this->bindFake();
        $payload = $this->payload('window-2', (new DateTimeImmutable())->modify('+1800 seconds'));

        $this->dispatch(new MaintenanceStarted($payload));

        $activation = $fake->activations[0];

        self::assertSame('bypass-phrase', $activation['secret']);
        self::assertSame('/status', $activation['redirect']);
        self::assertSame(30, $activation['refresh']);

        $template = $activation['template'];
        $retry = $activation['retry'];
        self::assertIsString($template);
        self::assertIsInt($retry);
        self::assertStringContainsString('Down for maintenance', $template);
        self::assertStringContainsString((string) $retry, $template);
        self::assertStringNotContainsString('maintenance-window', $template);
    }

    /**
     * I3: the produced payload's `except` must mirror what the provider
     * registered with PreventRequestsDuringMaintenance, not an empty array —
     * otherwise a leftover storage/framework/maintenance.php stub from an
     * earlier `artisan down` can permanently swallow the `ended` webhook.
     */
    public function testStartedActivatesWithANonEmptyExceptListContainingTheWebhookPath(): void
    {
        config(['allsystems.maintenance.template' => 'maintenance-window']);

        $app = $this->app;
        assert($app !== null);
        $app->make(ViewFactory::class)->addLocation(__DIR__ . '/views');

        $fake = $this->bindFake();
        $payload = $this->payload('window-13', (new DateTimeImmutable())->modify('+1800 seconds'));

        $this->dispatch(new MaintenanceStarted($payload));

        $except = $fake->activations[0]['except'];
        self::assertIsArray($except);
        self::assertNotEmpty($except);
        self::assertContains('allsystems/webhook', $except);
    }

    public function testStartedRetryFloorsAtSixtySecondsWhenTheWindowHasAlreadyEnded(): void
    {
        $fake = $this->bindFake();
        $payload = $this->payload('window-3', (new DateTimeImmutable())->modify('-60 seconds'));

        $this->dispatch(new MaintenanceStarted($payload));

        self::assertSame(60, $fake->activations[0]['retry']);
    }

    /**
     * M3: a non-numeric refresh value must produce `null`, not `0` — a
     * `Refresh: 0` header tells browsers to reload immediately, in a loop,
     * for the whole maintenance window.
     */
    public function testStartedWithNonNumericRefreshConfigProducesNullNotZero(): void
    {
        config(['allsystems.maintenance.refresh' => 'not-a-number']);

        $fake = $this->bindFake();
        $payload = $this->payload('window-14', (new DateTimeImmutable())->modify('+1800 seconds'));

        $this->dispatch(new MaintenanceStarted($payload));

        self::assertNull($fake->activations[0]['refresh']);
    }

    public function testStartedAlreadyActiveWithTheSameIdDoesNotReactivate(): void
    {
        $fake = $this->bindFake();
        $fake->activate([SyncMaintenanceMode::ID_KEY => 'window-4', 'except' => []]);

        $this->dispatch(new MaintenanceStarted($this->payload('window-4')));

        self::assertCount(1, $fake->activations);
        self::assertSame('noop', $this->syncResult()->action);
    }

    public function testStartedAlreadyActiveWithADifferentIdSupersedesIt(): void
    {
        $fake = $this->bindFake();
        $fake->activate([SyncMaintenanceMode::ID_KEY => 'window-5a', 'except' => []]);

        $this->dispatch(new MaintenanceStarted($this->payload('window-5b')));

        self::assertCount(2, $fake->activations);
        self::assertSame('window-5b', $fake->activations[1][SyncMaintenanceMode::ID_KEY]);
        self::assertSame('activated', $this->syncResult()->action);
    }

    public function testEndedOursDeactivatesAndSetsActionDeactivated(): void
    {
        $fake = $this->bindFake();
        $fake->activate([SyncMaintenanceMode::ID_KEY => 'window-6', 'except' => []]);

        $this->dispatch(new MaintenanceEnded($this->payload('window-6')));

        self::assertSame(1, $fake->deactivateCalls);
        self::assertFalse($fake->active());
        self::assertSame('deactivated', $this->syncResult()->action);
    }

    public function testEndedWhenNotActiveDoesNotDeactivate(): void
    {
        $fake = $this->bindFake();

        $this->dispatch(new MaintenanceEnded($this->payload('window-7')));

        self::assertSame(0, $fake->deactivateCalls);
        self::assertSame('noop', $this->syncResult()->action);
    }

    /**
     * This is the test the package exists to keep passing. A hand-run
     * `artisan down` has no allsystems_maintenance_id in its data array at
     * all — see the shape produced by DownCommand::getDownFilePayload().
     */
    public function testEndedDoesNotLiftAHandRunArtisanDown(): void
    {
        $fake = $this->bindFake();
        $fake->activate([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ]);

        $this->dispatch(new MaintenanceEnded($this->payload('window-8')));

        self::assertSame(0, $fake->deactivateCalls, 'A hand-run artisan down must never be lifted by an AllSystems window ending.');
        self::assertSame('noop', $this->syncResult()->action);
    }

    /**
     * The composed case C1 fixes: a foreign maintenance mode (no
     * allsystems_maintenance_id at all) is active when `started` arrives.
     * `started` must not take it over — and because it doesn't, the matching
     * `ended` has nothing of ours to lift either.
     */
    public function testStartedDoesNotTakeOverAForeignMaintenanceModeAndSubsequentEndedDoesNotLiftIt(): void
    {
        $fake = $this->bindFake();
        $fake->activate([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => 'let-me-in',
            'status' => 503,
            'template' => null,
        ]);

        $this->dispatch(new MaintenanceStarted($this->payload('window-12')));

        self::assertCount(1, $fake->activations, 'started must not call activate() over a foreign maintenance mode.');
        self::assertSame('noop', $this->syncResult()->action);
        self::assertTrue($fake->active());

        $this->dispatch(new MaintenanceEnded($this->payload('window-12')));

        self::assertSame(0, $fake->deactivateCalls, 'A foreign maintenance mode must never be lifted by AllSystems.');
        self::assertTrue($fake->active());
        self::assertSame('noop', $this->syncResult()->action);
    }

    public function testEndedDoesNotLiftADifferentWindowsId(): void
    {
        $fake = $this->bindFake();
        $fake->activate([SyncMaintenanceMode::ID_KEY => 'window-9a', 'except' => []]);

        $this->dispatch(new MaintenanceEnded($this->payload('window-9b')));

        self::assertSame(0, $fake->deactivateCalls);
        self::assertSame('noop', $this->syncResult()->action);
    }

    public function testMaintenanceDisabledListenerIsNotRegisteredButEventsStillDispatchAndWebhookStillReturns200(): void
    {
        $fake = $this->bindFake();

        $dispatched = false;
        Event::listen(MaintenanceStarted::class, function () use (&$dispatched): void {
            $dispatched = true;
        });

        $body = $this->maintenanceBody('window-10');

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('maintenance.started', $body), $body)
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'noop'])
        ;

        self::assertTrue($dispatched, 'The event must still dispatch even when the listener is not registered.');
        self::assertSame([], $fake->activations);
        self::assertSame(0, $fake->deactivateCalls);
    }

    /**
     * The real, file-backed MaintenanceMode — not the fake — driven over real
     * signed HTTP, proving the whole chain: activate on `started`, the app
     * really is down, the `ended` webhook is reachable anyway (B2's
     * exemption), and it lifts the *same* window's maintenance mode.
     */
    public function testEndToEndSignedStartedThenEndedAppliesAndLiftsMaintenanceMode(): void
    {
        $app = $this->app;
        assert($app !== null);
        $kernel = $app->make(HttpKernelContract::class);
        assert($kernel instanceof FoundationHttpKernel);
        $kernel->pushMiddleware(PreventRequestsDuringMaintenance::class);

        $body = $this->maintenanceBody('window-11');

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('maintenance.started', $body), $body)
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'activated'])
        ;

        $this->get('/')->assertServiceUnavailable();

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('maintenance.ended', $body), $body)
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'deactivated'])
        ;

        self::assertFalse($app->make(MaintenanceMode::class)->active());
    }

    private function bindFake(): FakeMaintenanceMode
    {
        $fake = new FakeMaintenanceMode();

        $app = $this->app;
        assert($app !== null);
        $app->instance(MaintenanceMode::class, $fake);

        return $fake;
    }

    private function syncResult(): SyncResult
    {
        $app = $this->app;
        assert($app !== null);

        return $app->make(SyncResult::class);
    }

    private function dispatch(object $event): void
    {
        $app = $this->app;
        assert($app !== null);
        $app->make(Dispatcher::class)->dispatch($event);
    }

    private function payload(string $id, ?DateTimeImmutable $endsAt = null): MaintenancePayload
    {
        $now = new DateTimeImmutable();

        return new MaintenancePayload(
            id: $id,
            title: 'Database upgrade',
            message: 'Upgrading PostgreSQL.',
            startsAt: $now,
            endsAt: $endsAt ?? $now->modify('+1800 seconds'),
            components: [],
        );
    }

    private function maintenanceBody(string $id): string
    {
        return sprintf(
            '{"id":"%s","title":"Database upgrade","message":"Upgrading PostgreSQL.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[]}',
            $id,
        );
    }

    /** @return array<string, string> */
    private function signedHeaders(string $event, string $body): array
    {
        $timestamp = time();
        $v1 = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return $this->transformHeadersToServerVars([
            VerifySignature::HEADER => "t={$timestamp},v1={$v1}",
            'X-AllSystems-Event' => $event,
        ]);
    }
}
