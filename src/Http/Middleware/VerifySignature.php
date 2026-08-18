<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only thing standing between the public internet and your app's
 * maintenance mode.
 *
 *   X-AllSystems-Signature: t=<unix seconds>,v1=<hex hmac-sha256>
 *   v1 = HMAC-SHA256(secret, "{t}.{raw request body}")
 *
 * Three checks, all of which must pass:
 *
 *  1. The header parses into a `t` and a `v1`.
 *  2. |now - t| is within the configured tolerance. Without this, a signature
 *     captured once is valid forever and anyone who ever saw one can take the
 *     app down at will.
 *  3. hash_equals() over the two hex digests. Never `===`: a timing-variable
 *     comparison over an attacker-supplied digest leaks the expected value one
 *     byte at a time.
 *
 * The RAW body is what is signed — $request->getContent(), never a re-encode of
 * $request->json(). Re-encoding can change key order, whitespace or unicode
 * escaping, and the digest would then be over something the sender never sent.
 */
final readonly class VerifySignature
{
    public const HEADER = 'X-AllSystems-Signature';

    public function __construct(private ConfigRepository $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('allsystems.secret');

        if (!is_string($secret) || $secret === '') {
            // Unconfigured, not misconfigured — but the answer is the same:
            // nothing is authenticated, so nothing gets through.
            return $this->reject('AllSystems webhook secret is not configured.');
        }

        $parsed = self::parse((string) $request->headers->get(self::HEADER, ''));

        if ($parsed === null) {
            return $this->reject('Malformed or missing signature header.');
        }

        [$timestamp, $provided] = $parsed;

        $configuredTolerance = $this->config->get('allsystems.tolerance', 300);
        $tolerance = is_numeric($configuredTolerance) ? (int) $configuredTolerance : 300;

        if (abs(time() - $timestamp) > $tolerance) {
            return $this->reject('Signature timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (!hash_equals($expected, $provided)) {
            return $this->reject('Signature does not match.');
        }

        return $next($request);
    }

    /** @return array{0: int, 1: string}|null */
    private static function parse(string $header): ?array
    {
        $timestamp = null;
        $v1 = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && preg_match('/^\d{1,10}$/', $value) === 1) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $v1 = $value;
            }
        }

        return $timestamp === null || $v1 === null ? null : [$timestamp, $v1];
    }

    private function reject(string $reason): Response
    {
        // 401 and a bare reason. AllSystems records the status code, retries
        // for ~19 minutes, and a mid-window secret rotation therefore heals
        // itself once the app's ALLSYSTEMS_WEBHOOK_SECRET is updated.
        return new \Illuminate\Http\JsonResponse(['ok' => false, 'error' => $reason], 401);
    }
}
