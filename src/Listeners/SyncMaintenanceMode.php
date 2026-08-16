<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Listeners;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;

/**
 * Stub — B6 replaces this with the real, id-guarded maintenance-mode sync.
 * Exists now only so the service provider can register the listener bindings.
 */
final class SyncMaintenanceMode
{
    public function handleStarted(MaintenanceStarted $event): void
    {
    }

    public function handleEnded(MaintenanceEnded $event): void
    {
    }
}
