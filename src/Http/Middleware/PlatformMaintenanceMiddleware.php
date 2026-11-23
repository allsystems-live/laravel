<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Middleware;

use AllSystems\Laravel\PlatformMaintenance;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 503s every request during a platform window without touching the database, which a release may be migrating.
 * Exempt: the webhook path, so `ended` lands, plus allsystems.except (default `up`, for deploy smoke checks).
 */
final class PlatformMaintenanceMiddleware
{
    public function __construct(
        private readonly PlatformMaintenance $maintenance,
        private readonly ResponseFactory $responses,
        private readonly ConfigRepository $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Exemptions first, so they never read the cache.
        if ($request->is(...$this->exemptPaths()) || !$this->maintenance->isActive()) {
            return $next($request);
        }

        // no-store so the edge never caches the outage past the window.
        $headers = [
            'Retry-After' => $this->setting('allsystems.retry_after'),
            'Cache-Control' => 'no-store',
        ];

        if ($request->expectsJson()) {
            return new JsonResponse(
                [
                    'errors' => [
                        [
                            'status' => '503',
                            'title' => 'Service Unavailable',
                            'code' => 'platform_maintenance',
                            'detail' => $this->config->get('allsystems.json_detail'),
                        ],
                    ],
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'application/vnd.api+json'] + $headers,
            );
        }

        return $this->responses->view(
            $this->setting('allsystems.view'),
            [],
            Response::HTTP_SERVICE_UNAVAILABLE,
            $headers,
        );
    }

    private function setting(string $key): string
    {
        $value = $this->config->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private function exemptPaths(): array
    {
        $paths = [$this->config->get('allsystems.path'), ...(array) $this->config->get('allsystems.except')];

        return array_values(array_filter($paths, is_string(...)));
    }
}
