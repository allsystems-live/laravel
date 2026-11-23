<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits only deliveries AllSystems signed: `X-AllSystems-Signature: t=<unix>,v1=<hmac-sha256 of "t.body">`.
 *
 * Fails closed with no secret configured. The timestamp window bounds replays of a captured delivery.
 */
final class VerifySignature
{
    public const HEADER = 'X-AllSystems-Signature';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('allsystems.secret');

        if (!is_string($secret) || $secret === '') {
            return $this->reject('secret_unset');
        }

        $parts = $this->parse((string) $request->headers->get(self::HEADER));

        if ($parts === null) {
            return $this->reject('malformed_header');
        }

        [$timestamp, $signature] = $parts;
        $configuredTolerance = $this->config->get('allsystems.tolerance', 300);
        $tolerance = is_numeric($configuredTolerance) ? (int) $configuredTolerance : 300;

        if (abs($this->clock->now()->getTimestamp() - $timestamp) > $tolerance) {
            return $this->reject('timestamp_out_of_tolerance');
        }

        // The raw body: a re-encode would not be byte-identical to what AllSystems signed.
        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->reject('signature_mismatch');
        }

        return $next($request);
    }

    /** @return array{int, string}|null */
    private function parse(string $header): ?array
    {
        $fields = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $fields[$key] = $value;
        }

        $timestamp = $fields['t'] ?? '';
        $signature = $fields['v1'] ?? '';

        if (preg_match('/^\d{1,10}$/', $timestamp) !== 1 || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            return null;
        }

        return [(int) $timestamp, $signature];
    }

    private function reject(string $reason): JsonResponse
    {
        // The reason only: never the header or the body.
        $this->logger->warning('allsystems.webhook_rejected', ['reason' => $reason]);

        return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
    }
}
