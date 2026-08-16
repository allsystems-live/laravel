<?php

declare(strict_types=1);

namespace AllSystems\Laravel;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\Listeners\SyncMaintenanceMode;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;

final class AllSystemsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/allsystems.php', 'allsystems');
    }

    public function boot(ConfigRepository $config, Dispatcher $events): void
    {
        $this->publishes([
            __DIR__ . '/../config/allsystems.php' => $this->app->configPath('allsystems.php'),
        ], 'allsystems-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

        // THE non-obvious one. PreventRequestsDuringMaintenance is in Laravel's
        // GLOBAL middleware stack, so once maintenance mode is active every
        // request gets a 503 — including the `maintenance.ended` webhook that
        // is supposed to lift it. Without this exemption the first window an
        // app enters is the last, and it can only be recovered by hand with
        // `artisan up`.
        //
        // Registered unconditionally, even when maintenance.enabled is false:
        // an app that handles the events itself is even more likely to be down
        // when the `ended` arrives.
        $configuredPath = $config->get('allsystems.path', 'allsystems/webhook');
        $path = is_string($configuredPath) ? $configuredPath : 'allsystems/webhook';
        PreventRequestsDuringMaintenance::except([$path, '/' . ltrim($path, '/')]);

        if ((bool) $config->get('allsystems.maintenance.enabled', true)) {
            $events->listen(MaintenanceStarted::class, [SyncMaintenanceMode::class, 'handleStarted']);
            $events->listen(MaintenanceEnded::class, [SyncMaintenanceMode::class, 'handleEnded']);
        }
    }
}
