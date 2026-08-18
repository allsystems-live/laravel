<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\MaintenancePayload;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Golden vector 1's body is reproduced verbatim from the plan's §"Golden
 * signature vectors" — Part A generates it, Part B (this file) only parses
 * it, so it is never recomputed or reformatted here.
 */
final class MaintenancePayloadTest extends TestCase
{
    private const GOLDEN_VECTOR_1_BODY = '{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.started","title":"Database upgrade","message":"Upgrading PostgreSQL. Expect ~20 minutes of downtime.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[{"id":"01920000-0000-7000-8000-00000000c001","name":"Web app"},{"id":"01920000-0000-7000-8000-00000000c002","name":"Queue worker"}]}';

    public function testFromArrayParsesGoldenVector1(): void
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(self::GOLDEN_VECTOR_1_BODY, true, 512, JSON_THROW_ON_ERROR);

        $payload = MaintenancePayload::fromArray($body);

        self::assertSame('01920000-0000-7000-8000-00000000a001', $payload->id);
        self::assertSame('Database upgrade', $payload->title);
        self::assertSame('Upgrading PostgreSQL. Expect ~20 minutes of downtime.', $payload->message);
        self::assertSame('2026-08-20T01:00:00+00:00', $payload->startsAt->format(DATE_ATOM));
        self::assertSame('2026-08-20T01:30:00+00:00', $payload->endsAt->format(DATE_ATOM));
        self::assertSame(
            [
                ['id' => '01920000-0000-7000-8000-00000000c001', 'name' => 'Web app'],
                ['id' => '01920000-0000-7000-8000-00000000c002', 'name' => 'Queue worker'],
            ],
            $payload->components,
        );
    }

    public function testComponentsDefaultsToEmptyArrayWhenKeyIsAbsent(): void
    {
        $body = $this->validBody();
        unset($body['components']);

        $payload = MaintenancePayload::fromArray($body);

        self::assertSame([], $payload->components);
    }

    public function testFromArrayRejectsMissingId(): void
    {
        $body = $this->validBody();
        unset($body['id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or non-string "id"');

        MaintenancePayload::fromArray($body);
    }

    public function testFromArrayRejectsMissingStartsAt(): void
    {
        $body = $this->validBody();
        unset($body['starts_at']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or non-string "starts_at"');

        MaintenancePayload::fromArray($body);
    }

    public function testFromArrayRejectsEmptyStringTitle(): void
    {
        $body = $this->validBody();
        $body['title'] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or non-string "title"');

        MaintenancePayload::fromArray($body);
    }

    public function testFromArrayRejectsNumericId(): void
    {
        $body = $this->validBody();
        $body['id'] = 12345;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or non-string "id"');

        MaintenancePayload::fromArray($body);
    }

    /**
     * AllSystems always sends the literal `Z` suffix, never a numeric offset.
     * A body carrying `+00:00` instead is not a format this parser accepts,
     * even though it names the same instant.
     */
    public function testFromArrayRejectsStartsAtWithNumericOffsetInsteadOfZ(): void
    {
        $body = $this->validBody();
        $body['starts_at'] = '2026-08-20T01:00:00+00:00';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unparseable "starts_at"');

        MaintenancePayload::fromArray($body);
    }

    /**
     * M2: DateTimeImmutable::createFromFormat silently rolls impossible
     * dates over into the next valid one instead of failing — 2026-02-31
     * becomes 2026-03-03. That must be rejected loudly, per this class's own
     * docblock, not turned into a plausible-looking but wrong Retry-After.
     */
    public function testFromArrayRejectsAnImpossibleStartsAtInsteadOfRollingItOver(): void
    {
        $body = $this->validBody();
        $body['starts_at'] = '2026-02-31T00:00:00Z';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unparseable "starts_at"');

        MaintenancePayload::fromArray($body);
    }

    public function testFromArrayRejectsAnImpossibleEndsAtInsteadOfRollingItOver(): void
    {
        $body = $this->validBody();
        $body['ends_at'] = '2026-13-45T99:00:00Z';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unparseable "ends_at"');

        MaintenancePayload::fromArray($body);
    }

    public function testRetryAfterSecondsReturnsRealRemainderMidWindow(): void
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(self::GOLDEN_VECTOR_1_BODY, true, 512, JSON_THROW_ON_ERROR);
        $payload = MaintenancePayload::fromArray($body);

        $now = new DateTimeImmutable('2026-08-20T01:27:00Z');

        self::assertSame(180, $payload->retryAfterSeconds($now));
    }

    public function testRetryAfterSecondsIsFlooredAtSixtyWhenWindowHasAlreadyEnded(): void
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(self::GOLDEN_VECTOR_1_BODY, true, 512, JSON_THROW_ON_ERROR);
        $payload = MaintenancePayload::fromArray($body);

        $now = new DateTimeImmutable('2026-08-20T02:00:00Z');

        self::assertSame(60, $payload->retryAfterSeconds($now));
    }

    /** @return array<string, mixed> */
    private function validBody(): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(self::GOLDEN_VECTOR_1_BODY, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
