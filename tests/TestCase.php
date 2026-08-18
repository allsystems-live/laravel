<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\AllSystemsServiceProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected const SECRET = 'whsec_test_31d4a1f7c0b84e6fa9d2c5e8b7043f16';

    protected function setUp(): void
    {
        // Static state on a framework class survives between tests in the same
        // process; without this, one test's registered exemption leaks into the
        // next and the assertion that it was registered passes vacuously.
        PreventRequestsDuringMaintenance::flushState();

        parent::setUp();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AllSystemsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Testbench's own signature leaves $app untyped; it is always an
        // Application at the call site, just not statically provable here.
        assert($app instanceof Application);

        $app->make(ConfigRepository::class)->set('allsystems.secret', self::SECRET);
    }
}
