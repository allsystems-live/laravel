<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stub — B4 replaces this with real HMAC signature verification. Exists now
 * only so `routes/webhook.php` resolves and the route can be registered.
 */
final class VerifySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
