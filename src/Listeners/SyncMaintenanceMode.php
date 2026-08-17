<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Listeners;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\SyncResult;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Drives Laravel's own maintenance mode from an AllSystems window.
 *
 * Everything here goes through the MaintenanceMode contract and nothing else.
 * Whichever driver backs it — file, cache, something you wrote — is your app's
 * `APP_MAINTENANCE_DRIVER` choice and this package neither knows nor cares.
 * That is what makes it work identically on one box, a fleet and Lambda.
 *
 * The id guard on deactivate() is the most important line in the package. An
 * operator who ran `artisan down` by hand has no allsystems_maintenance_id in
 * the payload; a *different* AllSystems window has a different one. In either
 * case AllSystems must not lift it. Getting this wrong means a scheduled
 * window's end quietly brings a deliberately-downed production app back up.
 */
final readonly class SyncMaintenanceMode
{
    /** The marker that makes a maintenance mode ours. */
    public const ID_KEY = 'allsystems_maintenance_id';

    public function __construct(
        private MaintenanceMode $maintenanceMode,
        private ConfigRepository $config,
        private ViewFactory $views,
        private SyncResult $result,
    ) {
    }

    public function handleStarted(MaintenanceStarted $event): void
    {
        $payload = $event->payload;

        if ($this->maintenanceMode->active() && ($this->maintenanceMode->data()[self::ID_KEY] ?? null) === $payload->id) {
            // A redelivery of a window we already applied. At-least-once
            // delivery makes this normal, not exceptional.
            $this->result->action = 'noop';

            return;
        }

        $retry = $payload->retryAfterSeconds(new DateTimeImmutable());

        // Laravel's PreventRequestsDuringMaintenance reads these with isset(),
        // so a null is correctly ignored — but only if the key's VALUE is null.
        // The keys mirror Illuminate\Foundation\Console\DownCommand exactly, so
        // an AllSystems-driven down behaves the same as `artisan down`.
        $this->maintenanceMode->activate([
            'except' => [],
            'redirect' => self::nullableString($this->config->get('allsystems.maintenance.redirect')),
            'retry' => $retry,
            'refresh' => self::nullableInt($this->config->get('allsystems.maintenance.refresh')),
            'secret' => self::nullableString($this->config->get('allsystems.maintenance.secret')),
            'status' => 503,
            'template' => $this->template($retry),
            self::ID_KEY => $payload->id,
        ]);

        $this->result->action = 'activated';
    }

    public function handleEnded(MaintenanceEnded $event): void
    {
        if (!$this->maintenanceMode->active()) {
            $this->result->action = 'noop';

            return;
        }

        if (($this->maintenanceMode->data()[self::ID_KEY] ?? null) !== $event->payload->id) {
            // Somebody else's maintenance mode — a hand-run `artisan down`, or
            // a different window. Not ours to lift.
            $this->result->action = 'noop';

            return;
        }

        $this->maintenanceMode->deactivate();
        $this->result->action = 'deactivated';
    }

    /**
     * `template` must be pre-rendered HTML, not a view name — Laravel serves
     * the string verbatim, and DownCommand renders the view itself for exactly
     * this reason. `retryAfter` is the variable Laravel's own maintenance views
     * expect.
     */
    private function template(int $retry): ?string
    {
        $view = self::nullableString($this->config->get('allsystems.maintenance.template'));

        if ($view === null) {
            return null;
        }

        return $this->views->make($view, ['retryAfter' => $retry])->render();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : null;
    }
}
