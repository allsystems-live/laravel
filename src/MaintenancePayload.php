<?php

declare(strict_types=1);

namespace AllSystems\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The `maintenance.started` / `maintenance.ended` body, typed.
 *
 * Parsing is strict: a body missing a field, or carrying an unparseable
 * timestamp, is a contract violation and must be rejected loudly rather than
 * quietly defaulted. A payload that half-parses is how an application ends up
 * in maintenance mode with a retry-after of zero.
 */
final readonly class MaintenancePayload
{
    /** @param list<array{id: string, name: string}> $components */
    public function __construct(
        public string $id,
        public string $title,
        public string $message,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public array $components,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $body): self
    {
        $components = [];

        /** @var list<mixed> $rawComponents */
        $rawComponents = is_array($body['components'] ?? null) ? array_values($body['components']) : [];

        foreach ($rawComponents as $component) {
            if (!is_array($component)) {
                continue;
            }

            $components[] = [
                'id' => self::string($component, 'id'),
                'name' => self::string($component, 'name'),
            ];
        }

        return new self(
            id: self::string($body, 'id'),
            title: self::string($body, 'title'),
            message: self::string($body, 'message'),
            startsAt: self::timestamp($body, 'starts_at'),
            endsAt: self::timestamp($body, 'ends_at'),
            components: $components,
        );
    }

    /**
     * Seconds until the window is planned to end, for Laravel's Retry-After.
     *
     * Floored at 60: a Retry-After of 0 or a negative one tells a crawler to
     * come straight back, and a window whose planned end has already passed
     * (a redelivery, or an operator who extended it) still wants a sane hint
     * rather than a hostile one.
     */
    public function retryAfterSeconds(DateTimeImmutable $now): int
    {
        return max(60, $this->endsAt->getTimestamp() - $now->getTimestamp());
    }

    /** @param array<array-key, mixed> $source */
    private static function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Missing or non-string "%s" in the AllSystems webhook payload.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function timestamp(array $source, string $key): DateTimeImmutable
    {
        $raw = self::string($source, $key);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC'));

        if ($parsed === false) {
            throw new InvalidArgumentException(sprintf('Unparseable "%s" in the AllSystems webhook payload: %s', $key, $raw));
        }

        // createFromFormat carries the current time's microseconds through even
        // when the format has no sub-second component, which makes two payloads
        // parsed a millisecond apart compare unequal.
        return $parsed->setTime(
            (int) $parsed->format('H'),
            (int) $parsed->format('i'),
            (int) $parsed->format('s'),
        );
    }
}
