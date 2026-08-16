<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Stub — B5 replaces this with the real webhook verification/dispatch flow.
 * Exists now only so `routes/webhook.php` resolves and the route can be
 * registered.
 */
final class WebhookController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
