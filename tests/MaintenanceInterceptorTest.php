<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Contracts\MaintenanceInterceptor;
use AllSystems\Laravel\Http\Controllers\WebhookController;
use AllSystems\Laravel\Http\Middleware\VerifySignature;
use AllSystems\Laravel\PlatformMaintenance;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

final class MaintenanceInterceptorTest extends TestCase
{
    private const URL = 'http://localhost/webhooks/allsystems';

    private RecordingInterceptor $double;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        $this->double = new RecordingInterceptor();

        if ($this->name() !== 'testUnboundInterceptorResolvesToNull') {
            $app->instance(MaintenanceInterceptor::class, $this->double);
        }
    }

    public function testStartedClaimedAsActivatedLeavesThePlatformWindowShut(): void
    {
        $this->double->started = 'activated';
        $body = $this->fullBody('maintenance.started', 'window-1');

        $this->deliver($body)->assertOk()->assertExactJson(['action' => 'activated']);

        self::assertFalse($this->maintenance()->isActive());
        self::assertSame([['started', 'window-1', json_decode($body, true)]], $this->double->calls);
    }

    public function testStartedClaimedAsNoopLeavesThePlatformWindowShut(): void
    {
        $this->double->started = 'noop';

        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk()->assertExactJson(['action' => 'noop']);
        self::assertFalse($this->maintenance()->isActive());
    }

    public function testStartedNotClaimedOpensThePlatformWindow(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk()->assertExactJson(['action' => 'activated']);
        self::assertTrue($this->maintenance()->isActive());
    }

    public function testEndedClaimedLeavesTheOpenWindowOpen(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk();
        $this->double->ended = 'deactivated';
        $body = $this->fullBody('maintenance.ended', 'window-1');

        $this->deliver($body)->assertOk()->assertExactJson(['action' => 'deactivated']);

        self::assertTrue($this->maintenance()->isActive());
        self::assertSame(['ended', 'window-1', json_decode($body, true)], $this->double->calls[1]);
    }

    public function testEndedNotClaimedClosesTheOpenWindow(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk();

        $this->deliver($this->body('maintenance.ended', 'window-1'))->assertOk()->assertExactJson(['action' => 'deactivated']);
        self::assertFalse($this->maintenance()->isActive());
    }

    /** @return array<string, array{string, string, string}> */
    public static function outOfContractReturns(): array
    {
        return [
            'started deactivated' => ['started', 'deactivated', 'activated'],
            'started ignored' => ['started', 'ignored', 'activated'],
            'ended noop' => ['ended', 'noop', 'deactivated'],
        ];
    }

    /** @param 'activated'|'noop'|'deactivated'|'ignored' $returned */
    #[DataProvider('outOfContractReturns')]
    public function testOutOfContractReturnsAreTreatedAsNull(string $method, string $returned, string $expected): void
    {
        if ($method === 'ended') {
            $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk();
        }

        $method === 'started' ? $this->double->started = $returned : $this->double->ended = $returned;

        $this->deliver($this->body("maintenance.{$method}", 'window-1'))->assertOk()->assertExactJson(['action' => $expected]);
        self::assertSame($method === 'started', $this->maintenance()->isActive());
    }

    /** @return array<string, array{string, bool}> */
    public static function undeliveredCases(): array
    {
        return [
            'ping' => ['{"event":"ping"}', true],
            'unknown event' => ['{"event":"maintenance.rescheduled","id":"window-1"}', true],
            'bad signature' => ['{"event":"maintenance.started","id":"window-1"}', false],
            'not json' => ['not json', true],
            'empty id' => ['{"event":"maintenance.started","id":""}', true],
        ];
    }

    #[DataProvider('undeliveredCases')]
    public function testTheInterceptorIsNotCalledFor(string $body, bool $signed): void
    {
        $signed
            ? $this->deliver($body)
            : $this->send($body, $this->header($this->clock->now()->getTimestamp(), $body . ' '));

        self::assertSame([], $this->double->calls);
    }

    public function testUnboundInterceptorResolvesToNull(): void
    {
        self::assertInstanceOf(WebhookController::class, $this->app?->make(WebhookController::class));

        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk()->assertExactJson(['action' => 'activated']);
        self::assertTrue($this->maintenance()->isActive());
    }

    private function maintenance(): PlatformMaintenance
    {
        $maintenance = $this->app?->make(PlatformMaintenance::class);
        assert($maintenance instanceof PlatformMaintenance);

        return $maintenance;
    }

    private function body(string $event, string $id): string
    {
        return json_encode(['id' => $id, 'event' => $event], JSON_THROW_ON_ERROR);
    }

    private function fullBody(string $event, string $id): string
    {
        return json_encode([
            'id' => $id,
            'event' => $event,
            'title' => 'Database upgrade',
            'components' => [['id' => 'c1', 'name' => 'Web app']],
        ], JSON_THROW_ON_ERROR);
    }

    private function header(int $timestamp, string $signedBody): string
    {
        return "t={$timestamp},v1=" . hash_hmac('sha256', $timestamp . '.' . $signedBody, self::SECRET);
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function deliver(string $body): TestResponse
    {
        return $this->send($body, $this->header($this->clock->now()->getTimestamp(), $body));
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function send(string $body, string $signature): TestResponse
    {
        $headers = $this->transformHeadersToServerVars([VerifySignature::HEADER => $signature]);

        return $this->call('POST', self::URL, [], [], [], $headers, $body);
    }
}

final class RecordingInterceptor implements MaintenanceInterceptor
{
    /** @var 'activated'|'noop'|'deactivated'|'ignored'|null */
    public ?string $started = null;

    /** @var 'activated'|'noop'|'deactivated'|'ignored'|null */
    public ?string $ended = null;

    /** @var list<array{string, string, array<string, mixed>}> */
    public array $calls = [];

    /** @return 'activated'|'noop'|'deactivated'|'ignored'|null */
    public function started(string $windowId, array $payload): ?string
    {
        $this->calls[] = ['started', $windowId, $payload];

        return $this->started;
    }

    /** @return 'activated'|'noop'|'deactivated'|'ignored'|null */
    public function ended(string $windowId, array $payload): ?string
    {
        $this->calls[] = ['ended', $windowId, $payload];

        return $this->ended;
    }
}
