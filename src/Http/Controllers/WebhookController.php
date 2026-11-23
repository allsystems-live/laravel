<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Controllers;

use AllSystems\Laravel\PlatformMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Applies a signed AllSystems delivery to the platform maintenance window.
 *
 * The event comes from the signed body, never the X-AllSystems-Event header, which the signature does not cover.
 * Unknown events answer 200 `ignored`, so a future event type is not retried by every deployed receiver.
 */
final class WebhookController
{
    public function __construct(private readonly PlatformMaintenance $maintenance)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid();
        }

        if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
            return $this->invalid();
        }

        if ($payload['event'] === 'ping') {
            return $this->answer('noop');
        }

        if ($payload['event'] !== 'maintenance.started' && $payload['event'] !== 'maintenance.ended') {
            return $this->answer('ignored');
        }

        $id = $payload['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return $this->invalid();
        }

        return $this->answer(
            $payload['event'] === 'maintenance.ended' ? $this->maintenance->end($id) : $this->maintenance->start($id),
        );
    }

    private function invalid(): JsonResponse
    {
        return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    private function answer(string $action): JsonResponse
    {
        return new JsonResponse(['action' => $action], 200);
    }
}
