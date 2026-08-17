<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\Events\PingReceived;
use AllSystems\Laravel\Http\Middleware\VerifySignature;
use Illuminate\Support\Facades\Event;

/**
 * Drives real HTTP through the registered route with VerifySignature already
 * satisfied, exercising the controller's own decode/dispatch/respond logic.
 *
 * Event::fake() is the "real event listener spy" the plan calls for: it
 * intercepts the actual dispatcher, so a passing assertion here proves the
 * controller called dispatch(), not merely that the response looked right.
 */
final class WebhookControllerTest extends TestCase
{
    private const PATH = '/allsystems/webhook';

    private const VALID_MAINTENANCE_BODY = '{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.started","title":"Database upgrade","message":"Upgrading PostgreSQL.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[]}';

    private const PING_BODY = '{"event":"ping","application":{"id":"01920000-0000-7000-8000-00000000b001","name":"Storefront"}}';

    public function testSignedMaintenanceStartedDispatchesExactlyOneEventAndReturnsOk(): void
    {
        Event::fake();

        $this->call(
            'POST',
            self::PATH,
            [],
            [],
            [],
            $this->signedHeaders('maintenance.started', self::VALID_MAINTENANCE_BODY),
            self::VALID_MAINTENANCE_BODY,
        )
            ->assertOk()
            ->assertJson(['ok' => true])
        ;

        Event::assertDispatchedTimes(MaintenanceStarted::class, 1);
        Event::assertDispatched(
            MaintenanceStarted::class,
            static fn (MaintenanceStarted $event): bool => $event->payload->id === '01920000-0000-7000-8000-00000000a001',
        );
        Event::assertNotDispatched(MaintenanceEnded::class);
        Event::assertNotDispatched(PingReceived::class);
    }

    public function testSignedMaintenanceEndedDispatchesExactlyOneEventAndReturnsOk(): void
    {
        Event::fake();

        $this->call(
            'POST',
            self::PATH,
            [],
            [],
            [],
            $this->signedHeaders('maintenance.ended', self::VALID_MAINTENANCE_BODY),
            self::VALID_MAINTENANCE_BODY,
        )
            ->assertOk()
            ->assertJson(['ok' => true])
        ;

        Event::assertDispatchedTimes(MaintenanceEnded::class, 1);
        Event::assertDispatched(
            MaintenanceEnded::class,
            static fn (MaintenanceEnded $event): bool => $event->payload->id === '01920000-0000-7000-8000-00000000a001',
        );
        Event::assertNotDispatched(MaintenanceStarted::class);
        Event::assertNotDispatched(PingReceived::class);
    }

    public function testSignedPingDispatchesExactlyOnePingReceivedAndReturnsNoop(): void
    {
        Event::fake();

        $response = $this->call(
            'POST',
            self::PATH,
            [],
            [],
            [],
            $this->signedHeaders('ping', self::PING_BODY),
            self::PING_BODY,
        );

        $response->assertOk()->assertJson(['ok' => true, 'action' => 'noop']);

        Event::assertDispatchedTimes(PingReceived::class, 1);
        Event::assertDispatched(
            PingReceived::class,
            static fn (PingReceived $event): bool => $event->applicationId === '01920000-0000-7000-8000-00000000b001'
                && $event->applicationName === 'Storefront',
        );
        Event::assertNotDispatched(MaintenanceStarted::class);
        Event::assertNotDispatched(MaintenanceEnded::class);
    }

    public function testUnknownEventReturnsOkNoopAndDispatchesNothing(): void
    {
        Event::fake();

        $body = '{"event":"something.unknown"}';

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('something.unknown', $body), $body)
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'noop'])
        ;

        $this->assertNoneOfOurEventsWereDispatched();
    }

    public function testMalformedJsonBodyReturns400(): void
    {
        Event::fake();

        $body = 'not-json';

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('maintenance.started', $body), $body)
            ->assertStatus(400)
        ;

        $this->assertNoneOfOurEventsWereDispatched();
    }

    public function testMaintenanceStartedMissingStartsAtReturns422AndDispatchesNothing(): void
    {
        Event::fake();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::VALID_MAINTENANCE_BODY, true, 512, JSON_THROW_ON_ERROR);
        unset($decoded['starts_at']);
        $body = json_encode($decoded, JSON_THROW_ON_ERROR);

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders('maintenance.started', $body), $body)
            ->assertStatus(422)
        ;

        $this->assertNoneOfOurEventsWereDispatched();
    }

    public function testGetIsMethodNotAllowed(): void
    {
        $this->get(self::PATH)->assertStatus(405);
    }

    private function assertNoneOfOurEventsWereDispatched(): void
    {
        Event::assertNotDispatched(MaintenanceStarted::class);
        Event::assertNotDispatched(MaintenanceEnded::class);
        Event::assertNotDispatched(PingReceived::class);
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
