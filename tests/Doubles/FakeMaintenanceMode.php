<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests\Doubles;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/**
 * In-memory `MaintenanceMode` double. No filesystem, so a test built on this
 * says nothing about the file driver, the cache driver, or any other — only
 * about what SyncMaintenanceMode did through the contract.
 *
 * `activations` records every `activate()` payload, in order, so a test can
 * assert both "how many times" and "with exactly what". `deactivateCalls`
 * is the id-guard tests' load-bearing assertion: the guard exists to keep
 * that count at zero for a maintenance mode AllSystems does not own.
 */
final class FakeMaintenanceMode implements MaintenanceMode
{
    /** @var list<array<string, mixed>> */
    public array $activations = [];

    public int $deactivateCalls = 0;

    private bool $active = false;

    /** @param array<string, mixed> $payload */
    public function activate(array $payload): void
    {
        $this->activations[] = $payload;
        $this->active = true;
    }

    public function deactivate(): void
    {
        ++$this->deactivateCalls;
        $this->active = false;
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        if ($this->activations === []) {
            return [];
        }

        return $this->activations[array_key_last($this->activations)];
    }
}
