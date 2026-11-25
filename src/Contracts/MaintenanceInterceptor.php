<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Contracts;

/**
 * An app binds this in a service provider's register() to claim a window before the platform window opens or closes.
 *
 * Only signed, valid maintenance deliveries reach it. Any return outside the documented values is treated as null.
 */
interface MaintenanceInterceptor
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return 'activated'|'noop'|null null lets the platform window open
     */
    public function started(string $windowId, array $payload): ?string;

    /**
     * @param array<string, mixed> $payload
     *
     * @return 'deactivated'|null null lets PlatformMaintenance::end() run
     */
    public function ended(string $windowId, array $payload): ?string;
}
