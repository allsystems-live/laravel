<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

use function config;

final class ServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        // The file maintenance driver writes real state to disk; activate()
        // in the exemption test must not leak "down" into later tests.
        $app = $this->app;

        if ($app !== null) {
            $maintenanceMode = $app->make(MaintenanceMode::class);

            if ($maintenanceMode->active()) {
                $maintenanceMode->deactivate();
            }
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        // Only the path-override test needs a non-default path; every other
        // test relies on the documented default staying in effect.
        if ($this->name() === 'testConsumerPathOverrideMovesTheRoute') {
            $app->make(ConfigRepository::class)->set('allsystems.path', 'custom/hook');
        }
    }

    public function testConfigMergesWithTheDocumentedDefaults(): void
    {
        self::assertSame('allsystems/webhook', config('allsystems.path'));
        self::assertSame(300, config('allsystems.tolerance'));
        self::assertTrue(config('allsystems.maintenance.enabled'));
    }

    public function testConsumerPathOverrideMovesTheRoute(): void
    {
        $this->postJson('/custom/hook')->assertOk();
        $this->postJson('/allsystems/webhook')->assertNotFound();
    }

    public function testTheRouteIsNamedAndCarriesOnlyVerifySignature(): void
    {
        $route = Route::getRoutes()->getByName('allsystems.webhook');

        self::assertInstanceOf(RoutingRoute::class, $route);

        self::assertSame(['AllSystems\Laravel\Http\Middleware\VerifySignature'], $route->gatherMiddleware());
    }

    /**
     * The whole reason B2 exists: PreventRequestsDuringMaintenance is a
     * GLOBAL middleware in a real host app, so it is pushed onto the kernel
     * here to reproduce that, rather than attached only to a test route.
     */
    public function testTheMaintenanceModeExemptionKeepsTheWebhookPathReachable(): void
    {
        $app = $this->app;
        assert($app !== null);

        $kernel = $app->make(HttpKernelContract::class);
        assert($kernel instanceof FoundationHttpKernel);
        $kernel->pushMiddleware(PreventRequestsDuringMaintenance::class);

        $app->make(MaintenanceMode::class)->activate([
            'except' => [],
            'status' => 503,
        ]);

        $this->postJson('/allsystems/webhook')->assertOk();
        $this->get('/')->assertServiceUnavailable();
    }
}
