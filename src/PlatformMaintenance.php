<?php

declare(strict_types=1);

namespace AllSystems\Laravel;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The flag lives in the default (shared) cache store, so every Lambda agrees on the window without the database
 * a release may be migrating.
 * It expires after allsystems.max_minutes, so a lost `ended` cannot keep the app down.
 */
final class PlatformMaintenance
{
    private const DEFAULT_KEY = 'allsystems:platform-maintenance';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return 'activated'|'noop' */
    public function start(string $windowId): string
    {
        $state = $this->state();

        // A redelivery must not extend the window it already opened.
        if ($state !== null && $state['id'] === $windowId && !$this->expired($state)) {
            return 'noop';
        }

        $startedAt = $this->clock->now()->getTimestamp();
        $this->store()->forever($this->key(), [
            'id' => $windowId,
            'started_at' => $startedAt,
            'expires_at' => $startedAt + $this->maxMinutes() * 60,
        ]);

        $this->logger->info('platform_maintenance.started', ['id' => $windowId]);

        return 'activated';
    }

    /** @return 'deactivated'|'noop' */
    public function end(string $windowId): string
    {
        $state = $this->state();

        // Another window's `ended` never lifts the current one.
        if ($state === null || $state['id'] !== $windowId) {
            return 'noop';
        }

        $this->store()->forget($this->key());
        $this->logger->info('platform_maintenance.ended', ['id' => $windowId]);

        return 'deactivated';
    }

    public function isActive(): bool
    {
        $state = $this->state();

        if ($state === null) {
            return false;
        }

        if ($this->expired($state)) {
            $this->store()->forget($this->key());
            $this->logger->warning('platform_maintenance.expired', [
                'id' => $state['id'],
                'started_at' => (new DateTimeImmutable('@' . $state['started_at']))->format(DATE_ATOM),
            ]);

            return false;
        }

        return true;
    }

    /** @return array{id: string, started_at: int, expires_at: int}|null */
    private function state(): ?array
    {
        $state = $this->store()->get($this->key());

        if (!is_array($state) || !is_string($state['id'] ?? null)
            || !is_int($state['started_at'] ?? null) || !is_int($state['expires_at'] ?? null)) {
            return null;
        }

        return ['id' => $state['id'], 'started_at' => $state['started_at'], 'expires_at' => $state['expires_at']];
    }

    /** @param array{id: string, started_at: int, expires_at: int} $state */
    private function expired(array $state): bool
    {
        return $this->clock->now()->getTimestamp() >= $state['expires_at'];
    }

    private function maxMinutes(): int
    {
        $minutes = $this->config->get('allsystems.max_minutes');

        return is_numeric($minutes) ? (int) $minutes : 30;
    }

    private function key(): string
    {
        $key = $this->config->get('allsystems.cache_key');

        return is_string($key) && $key !== '' ? $key : self::DEFAULT_KEY;
    }

    private function store(): Repository
    {
        return $this->cache->store();
    }
}
