<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Http\Middleware\VerifySignature;
use AllSystems\Laravel\PlatformMaintenance;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

final class WebhookTest extends TestCase
{
    private const URL = 'http://localhost/webhooks/allsystems';
    private const GOLDEN_BODY = '{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.started","title":"Database upgrade","message":"Upgrading PostgreSQL. Expect ~20 minutes of downtime.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[{"id":"01920000-0000-7000-8000-00000000c001","name":"Web app"},{"id":"01920000-0000-7000-8000-00000000c002","name":"Queue worker"}]}';
    private const GOLDEN_V1 = 'c8b3afe8c09a8412359958dcc6c1c86d7bed4318abb431b733c210068c264613';

    /** @var list<array{string, array<string, mixed>}> */
    private array $warnings = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        $config = $app->make(ConfigRepository::class);

        if ($this->name() === 'testAnUnsetOrEmptySecretRejectsAValidSignature') {
            $config->set('allsystems.secret', $this->dataName() === 'empty' ? '' : null);
        }

        if ($this->name() === 'testTheRouteServesOnlyTheConfiguredDomain') {
            $config->set('allsystems.domain', 'hooks.example.test');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->warnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });
        $this->app?->instance(LoggerInterface::class, $logger);
    }

    public function testStartedOpensTheWindowAndARedeliveryIsANoop(): void
    {
        $body = $this->body('maintenance.started', 'window-1');

        $this->deliver($body)->assertOk()->assertExactJson(['action' => 'activated']);
        self::assertTrue($this->maintenance()->isActive());

        $this->deliver($body)->assertOk()->assertExactJson(['action' => 'noop']);
        self::assertTrue($this->maintenance()->isActive());
    }

    public function testEndedForTheOpenWindowDeactivatesIt(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk();

        $this->deliver($this->body('maintenance.ended', 'window-1'))->assertOk()->assertExactJson(['action' => 'deactivated']);
        self::assertFalse($this->maintenance()->isActive());
    }

    public function testEndedForAnotherWindowIsANoopAndTheWindowStays(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))->assertOk();

        $this->deliver($this->body('maintenance.ended', 'window-2'))->assertOk()->assertExactJson(['action' => 'noop']);
        self::assertTrue($this->maintenance()->isActive());
    }

    public function testTheEventComesFromTheBodyNotTheHeader(): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'), event: 'maintenance.ended')
            ->assertOk()
            ->assertExactJson(['action' => 'activated'])
        ;
    }

    /** @return array<string, array{string}> */
    public static function rejectedSignatures(): array
    {
        return [
            'missing header' => ['missing'],
            'malformed header' => ['malformed'],
            'wrong secret' => ['wrong_secret'],
            'tampered body' => ['tampered'],
            'stale timestamp' => ['stale'],
            'future timestamp' => ['future'],
        ];
    }

    #[DataProvider('rejectedSignatures')]
    public function testRejectedSignaturesAnswer401AndLeaveTheWindowUntouched(string $case): void
    {
        $body = $this->body('maintenance.started', 'window-1');
        $now = $this->clock->now()->getTimestamp();
        $signed = fn (int $t, string $secret, string $over): string => "t={$t},v1=" . hash_hmac('sha256', $t . '.' . $over, $secret);

        $header = match ($case) {
            'missing' => null,
            'malformed' => 'nonsense',
            'wrong_secret' => $signed($now, 'other-secret', $body),
            'tampered' => $signed($now, self::SECRET, $body . ' '),
            'stale' => $signed($now - 301, self::SECRET, $body),
            default => $signed($now + 301, self::SECRET, $body),
        };

        $this->send($body, $header)->assertUnauthorized()->assertExactJson(['error' => 'Invalid signature']);
        self::assertFalse($this->maintenance()->isActive());
    }

    /** @return array<string, array{string}> */
    public static function unconfiguredSecrets(): array
    {
        return ['null' => ['null'], 'empty' => ['empty']];
    }

    #[DataProvider('unconfiguredSecrets')]
    public function testAnUnsetOrEmptySecretRejectsAValidSignature(string $case): void
    {
        $this->deliver($this->body('maintenance.started', 'window-1'))
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Invalid signature'])
        ;
        self::assertFalse($this->maintenance()->isActive());
    }

    public function testTheToleranceEdge(): void
    {
        $body = $this->body('maintenance.started', 'window-1');
        $now = $this->clock->now()->getTimestamp();

        $this->deliver($body, timestamp: $now - 300)->assertOk();
        $this->deliver($body, timestamp: $now - 301)->assertUnauthorized();
    }

    public function testAPingAnswersNoop(): void
    {
        $this->deliver('{"event":"ping"}')->assertOk()->assertExactJson(['action' => 'noop']);
    }

    public function testAnUnknownEventIsIgnored(): void
    {
        $this->deliver('{"event":"maintenance.rescheduled","id":"window-1"}')->assertOk()->assertExactJson(['action' => 'ignored']);
        self::assertFalse($this->maintenance()->isActive());
    }

    /** @return array<string, array{string}> */
    public static function invalidBodies(): array
    {
        return [
            'not json' => ['not json'],
            'scalar' => ['"maintenance.started"'],
            'list' => ['[]'],
            'no event' => ['{"id":"window-1"}'],
            'non-string event' => ['{"event":5,"id":"window-1"}'],
            'started without id' => ['{"event":"maintenance.started"}'],
            'ended with empty id' => ['{"event":"maintenance.ended","id":""}'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAnswer400(string $body): void
    {
        $this->deliver($body)->assertStatus(400)->assertExactJson(['error' => 'Invalid payload']);
        self::assertFalse($this->maintenance()->isActive());
    }

    public function testTheGoldenDeliveryOpensTheWindow(): void
    {
        $this->send(self::GOLDEN_BODY, 't=1787000000,v1=' . self::GOLDEN_V1)
            ->assertOk()
            ->assertExactJson(['action' => 'activated'])
        ;
    }

    /** @return array<string, array{string, string}> */
    public static function rejectionReasons(): array
    {
        return [
            'secret_unset' => ['secret_unset', 'unset'],
            'malformed_header' => ['malformed_header', 'malformed'],
            'timestamp_out_of_tolerance' => ['timestamp_out_of_tolerance', 'stale'],
            'signature_mismatch' => ['signature_mismatch', 'mismatch'],
        ];
    }

    #[DataProvider('rejectionReasons')]
    public function testARejectionLogsExactlyOneWarningWithOnlyTheReason(string $reason, string $case): void
    {
        $body = '{"event":"ping"}';
        $now = $this->clock->now()->getTimestamp();

        if ($case === 'unset') {
            $this->app?->make(ConfigRepository::class)->set('allsystems.secret', '');
        }

        $header = match ($case) {
            'malformed' => 'nonsense',
            'stale' => $this->header($now - 301, $body),
            'mismatch' => $this->header($now, $body . ' '),
            default => $this->header($now, $body),
        };

        $this->send($body, $header)->assertUnauthorized();

        self::assertSame([['allsystems.webhook_rejected', ['reason' => $reason]]], $this->warnings);
    }

    public function testTheRouteServesOnlyTheConfiguredDomain(): void
    {
        $body = '{"event":"ping"}';
        $headers = $this->transformHeadersToServerVars([VerifySignature::HEADER => $this->header($this->clock->now()->getTimestamp(), $body)]);

        $this->call('POST', 'http://hooks.example.test/webhooks/allsystems', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', 'http://other.example.test/webhooks/allsystems', [], [], [], $headers, $body)->assertNotFound();
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

    private function header(int $timestamp, string $signedBody): string
    {
        return "t={$timestamp},v1=" . hash_hmac('sha256', $timestamp . '.' . $signedBody, self::SECRET);
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function deliver(string $body, ?int $timestamp = null, ?string $event = null): TestResponse
    {
        $timestamp ??= $this->clock->now()->getTimestamp();

        return $this->send($body, $this->header($timestamp, $body), $event);
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function send(string $body, ?string $signature, ?string $event = null): TestResponse
    {
        $headers = [];

        if ($signature !== null) {
            $headers[VerifySignature::HEADER] = $signature;
        }

        if ($event !== null) {
            $headers['X-AllSystems-Event'] = $event;
        }

        return $this->call('POST', self::URL, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
