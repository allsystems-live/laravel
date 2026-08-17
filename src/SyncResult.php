<?php

declare(strict_types=1);

namespace AllSystems\Laravel;

/**
 * Request-scoped mutable holder the listener writes to and the controller
 * reads from, so the controller can report what happened without knowing
 * the listener — or any listener — exists.
 */
final class SyncResult
{
    public string $action = 'noop';
}
