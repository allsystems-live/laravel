<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\PlatformMaintenance;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PlatformMaintenanceTest extends TestCase
{
    private MockClock $clock;

    private PlatformMaintenance $maintenance;

    private LegacyMockInterface $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = Log::spy();
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-25 12:00:00'));
        $this->application()->instance(ClockInterface::class, $this->clock);
        $this->maintenance = $this->application()->make(PlatformMaintenance::class);
    }

    public function testInactiveByDefault(): void
    {
        self::assertFalse($this->maintenance->isActive());
    }

    public function testStartActivatesTheWindow(): void
    {
        self::assertSame('activated', $this->maintenance->start('w-1'));
        self::assertTrue($this->maintenance->isActive());
    }

    /** A redelivered `started` must not push the safety expiry out. */
    public function testTheSameIdTwiceIsANoopAndKeepsTheOriginalExpiry(): void
    {
        $this->maintenance->start('w-1');
        $this->clock->modify('+20 minutes');

        self::assertSame('noop', $this->maintenance->start('w-1'));

        $this->clock->modify('+10 minutes');
        self::assertFalse($this->maintenance->isActive(), 'The redelivery extended the window.');
    }

    public function testADifferentIdReplacesTheWindow(): void
    {
        $this->maintenance->start('w-1');

        self::assertSame('activated', $this->maintenance->start('w-2'));
        self::assertSame('noop', $this->maintenance->end('w-1'));
        self::assertTrue($this->maintenance->isActive());
        self::assertSame('deactivated', $this->maintenance->end('w-2'));
        self::assertFalse($this->maintenance->isActive());
    }

    public function testEndWithTheActiveIdDeactivates(): void
    {
        $this->maintenance->start('w-1');

        self::assertSame('deactivated', $this->maintenance->end('w-1'));
        self::assertFalse($this->maintenance->isActive());
    }

    public function testEndWithAnotherIdLeavesTheWindowOpen(): void
    {
        $this->maintenance->start('w-1');

        self::assertSame('noop', $this->maintenance->end('w-other'));
        self::assertTrue($this->maintenance->isActive());
    }

    public function testEndWhileInactiveIsANoop(): void
    {
        self::assertSame('noop', $this->maintenance->end('w-1'));
    }

    public function testTheWindowExpiresAfterMaxMinutesWithOneWarning(): void
    {
        $this->maintenance->start('w-1');
        $this->clock->modify('+29 minutes');
        self::assertTrue($this->maintenance->isActive());

        $this->clock->modify('+1 minute');
        self::assertFalse($this->maintenance->isActive());
        // Forgotten on expiry: a second check finds nothing, so nothing more is logged.
        self::assertFalse($this->maintenance->isActive());
        self::assertSame('noop', $this->maintenance->end('w-1'));

        $this->log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'platform_maintenance.expired'
                && $context['id'] === 'w-1'
                && $context['started_at'] === '2026-10-25T12:00:00+00:00',
        );
    }

    public function testMaxMinutesComesFromConfig(): void
    {
        config(['allsystems.max_minutes' => 5]);
        $this->maintenance->start('w-1');

        $this->clock->modify('+4 minutes');
        self::assertTrue($this->maintenance->isActive());
        $this->clock->modify('+1 minute');
        self::assertFalse($this->maintenance->isActive());
    }

    public function testTheFlagLivesInTheDefaultStore(): void
    {
        $this->maintenance->start('w-1');
        self::assertNotNull(Cache::store()->get('allsystems:platform-maintenance'));

        $this->maintenance->end('w-1');
        self::assertNull(Cache::store()->get('allsystems:platform-maintenance'));
    }

    /** Laravel's own down-mode store must not route the per-request check to the database. */
    public function testTheMaintenanceStoreConfigIsIgnored(): void
    {
        config(['app.maintenance.store' => 'database']);
        $factory = Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')
            ->withArgs(static fn (?string $name = null): bool => $name === null)
            ->atLeast()->once()
            ->andReturn(Cache::store('array'))
        ;
        $this->application()->instance(CacheFactory::class, $factory);
        $maintenance = $this->application()->make(PlatformMaintenance::class);

        self::assertSame('activated', $maintenance->start('w-1'));
        self::assertTrue($maintenance->isActive());
    }

    private function application(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }
}
