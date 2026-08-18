<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Events;

use AllSystems\Laravel\MaintenancePayload;

/** Fired when `X-AllSystems-Event: maintenance.ended` is received. */
final readonly class MaintenanceEnded
{
    public function __construct(
        public MaintenancePayload $payload,
    ) {
    }
}
