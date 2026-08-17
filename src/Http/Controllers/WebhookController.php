<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Controllers;

use AllSystems\Laravel\Events\MaintenanceEnded;
use AllSystems\Laravel\Events\MaintenanceStarted;
use AllSystems\Laravel\Events\PingReceived;
use AllSystems\Laravel\MaintenancePayload;
use AllSystems\Laravel\SyncResult;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/**
 * One action, three events, and a flat 200 for anything it understood.
 *
 * The response body is informational — AllSystems treats any 2xx as delivered —
 * but `action` is what makes a redelivery legible in a log: `noop` says the
 * receiver had already applied this exact window, which is the correct and
 * expected answer to at-least-once delivery, not a warning.
 *
 * Unknown events are a 200 `noop`, deliberately. A future AllSystems that adds
 * an event type must not find every deployed receiver retrying it for twenty
 * minutes and then marking it failed.
 */
final readonly class WebhookController
{
    public function __construct(
        private Dispatcher $events,
        private SyncResult $result,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var array<string, mixed> $body */
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new JsonResponse(['ok' => false, 'error' => 'Body is not valid JSON.'], 400);
        }

        $event = $request->headers->get('X-AllSystems-Event', '');

        try {
            match ($event) {
                'maintenance.started' => $this->events->dispatch(new MaintenanceStarted(MaintenancePayload::fromArray($body))),
                'maintenance.ended' => $this->events->dispatch(new MaintenanceEnded(MaintenancePayload::fromArray($body))),
                'ping' => $this->events->dispatch(self::ping($body)),
                default => null,
            };
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['ok' => true, 'action' => $this->result->action]);
    }

    /** @param array<string, mixed> $body */
    private static function ping(array $body): PingReceived
    {
        /** @var array<string, mixed> $application */
        $application = is_array($body['application'] ?? null) ? $body['application'] : [];

        return new PingReceived(
            applicationId: is_string($application['id'] ?? null) ? $application['id'] : '',
            applicationName: is_string($application['name'] ?? null) ? $application['name'] : '',
        );
    }
}
