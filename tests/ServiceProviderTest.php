<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Http\Middleware\VerifySignature;
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

        if ($this->name() === 'testEmptyConfiguredPathFallsBackToTheDefaultAndDoesNotExemptTheHomePage') {
            $app->make(ConfigRepository::class)->set('allsystems.path', '');
        }

        if ($this->name() === 'testWildcardConfiguredPathFallsBackToTheDefaultAndDoesNotExemptTheHomePage') {
            $app->make(ConfigRepository::class)->set('allsystems.path', '*');
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
        $this->call('POST', '/custom/hook', [], [], [], $this->signedHeaders('[]'), '[]')
            ->assertOk()
        ;
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

        $this->call('POST', '/allsystems/webhook', [], [], [], $this->signedHeaders('[]'), '[]')
            ->assertOk()
        ;
        $this->get('/')->assertServiceUnavailable();
    }

    /**
     * I2: an empty configured path (unset, blank `.env` value, or a
     * config:cache without publishing) must fall back to the documented
     * default rather than relocating the route to the site root and
     * exempting it from maintenance mode.
     */
    public function testEmptyConfiguredPathFallsBackToTheDefaultAndDoesNotExemptTheHomePage(): void
    {
        self::assertSame('allsystems/webhook', config('allsystems.path'));

        $app = $this->app;
        assert($app !== null);

        $kernel = $app->make(HttpKernelContract::class);
        assert($kernel instanceof FoundationHttpKernel);
        $kernel->pushMiddleware(PreventRequestsDuringMaintenance::class);

        $app->make(MaintenanceMode::class)->activate(['except' => [], 'status' => 503]);

        $this->call('POST', '/allsystems/webhook', [], [], [], $this->signedHeaders('[]'), '[]')
            ->assertOk()
        ;
        $this->get('/')->assertServiceUnavailable();
    }

    /**
     * I2: a wildcarded path would hand PreventRequestsDuringMaintenance::except
     * the whole app; it must be rejected the same way an empty path is.
     */
    public function testWildcardConfiguredPathFallsBackToTheDefaultAndDoesNotExemptTheHomePage(): void
    {
        self::assertSame('allsystems/webhook', config('allsystems.path'));

        $app = $this->app;
        assert($app !== null);

        $kernel = $app->make(HttpKernelContract::class);
        assert($kernel instanceof FoundationHttpKernel);
        $kernel->pushMiddleware(PreventRequestsDuringMaintenance::class);

        $app->make(MaintenanceMode::class)->activate(['except' => [], 'status' => 503]);

        $this->call('POST', '/allsystems/webhook', [], [], [], $this->signedHeaders('[]'), '[]')
            ->assertOk()
        ;
        $this->get('/')->assertServiceUnavailable();
    }

    /**
     * I2: exactly one entry — the second, '/'-prefixed variant that used to
     * be registered was redundant for a normal path (inExceptArray() trims
     * slashes off each pattern itself) and actively wrong for the empty-path
     * case (it matched the site root).
     */
    public function testExemptedPathsListHasExactlyOneEntryForANormalConfiguredPath(): void
    {
        $app = $this->app;
        assert($app !== null);

        $exemptions = $app->make(PreventRequestsDuringMaintenance::class);

        self::assertSame(['allsystems/webhook'], $exemptions->getExcludedPaths());
    }

    /** @return array<string, string> */
    private function signedHeaders(string $body): array
    {
        $timestamp = time();
        $v1 = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return $this->transformHeadersToServerVars([
            VerifySignature::HEADER => "t={$timestamp},v1={$v1}",
        ]);
    }
}
