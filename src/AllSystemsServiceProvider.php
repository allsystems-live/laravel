<?php

declare(strict_types=1);

namespace AllSystems\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

final class AllSystemsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/allsystems.php', 'allsystems');

        $this->app->bindIf(ClockInterface::class, SystemClock::class);
    }

    public function boot(ConfigRepository $config): void
    {
        $this->publishes([
            __DIR__ . '/../config/allsystems.php' => $this->app->configPath('allsystems.php'),
        ], 'allsystems-config');

        $configuredPath = $config->get('allsystems.path', 'webhooks/allsystems');
        $path = is_string($configuredPath) ? trim($configuredPath, '/') : '';

        if ($path === '' || str_contains($path, '*')) {
            // Empty (unset, blank .env value, or config:cache without publishing)
            // or wildcarded (which would hand PreventRequestsDuringMaintenance::except
            // the whole app) both fall back to the documented default rather than
            // relocating the route to the site root or exempting everything.
            $path = 'webhooks/allsystems';
        }

        $config->set('allsystems.path', $path);

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'allsystems');

        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

        // PreventRequestsDuringMaintenance is in Laravel's GLOBAL middleware stack, so after a hand-run
        // `artisan down` the `maintenance.ended` webhook would 503 too. The platform window is gated by
        // PlatformMaintenanceMiddleware instead, but this exemption keeps the webhook reachable either way.
        //
        // One entry: inExceptArray() trims leading/trailing slashes off each
        // pattern itself, so a second '/'-prefixed variant is redundant and (for
        // the empty-path case) was actively wrong — it matched the site root.
        PreventRequestsDuringMaintenance::except([$path]);
    }
}
