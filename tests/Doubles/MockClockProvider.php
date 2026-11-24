<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests\Doubles;

use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class MockClockProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance(ClockInterface::class, new MockClock());
    }
}
