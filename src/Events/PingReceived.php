<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Events;

/** Fired when `X-AllSystems-Event: ping` is received. */
final readonly class PingReceived
{
    public function __construct(
        public string $applicationId,
        public string $applicationName,
    ) {
    }
}
