<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The three golden vectors, reproduced verbatim from the plan's §"Golden
 * signature vectors" — the mirror of `allsystems`' own
 * `tests/Unit/Delivery/WebhookSignerTest.php`, which generates these same
 * three constants on the signing side. Never recomputed or reformatted here:
 * this file is the whole interoperability guarantee between the two repos,
 * checkable without an HTTP request.
 */
final class SignatureVectorsTest extends TestCase
{
    private const SECRET = 'whsec_test_31d4a1f7c0b84e6fa9d2c5e8b7043f16';
    private const TIMESTAMP = '1787000000';

    private const VECTOR_1_BODY = '{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.started","title":"Database upgrade","message":"Upgrading PostgreSQL. Expect ~20 minutes of downtime.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[{"id":"01920000-0000-7000-8000-00000000c001","name":"Web app"},{"id":"01920000-0000-7000-8000-00000000c002","name":"Queue worker"}]}';
    private const VECTOR_1_V1 = 'c8b3afe8c09a8412359958dcc6c1c86d7bed4318abb431b733c210068c264613';

    private const VECTOR_2_BODY = '{"id":"01920000-0000-7000-8000-00000000a001","event":"maintenance.ended","title":"Database upgrade","message":"Upgrading PostgreSQL. Expect ~20 minutes of downtime.","starts_at":"2026-08-20T01:00:00Z","ends_at":"2026-08-20T01:30:00Z","components":[{"id":"01920000-0000-7000-8000-00000000c001","name":"Web app"},{"id":"01920000-0000-7000-8000-00000000c002","name":"Queue worker"}]}';
    private const VECTOR_2_V1 = '104097da9e33039d4a16ec31c821c282bdb64fe7f08144bc02b5b26e001cc1e0';

    private const VECTOR_3_BODY = '{"event":"ping","application":{"id":"01920000-0000-7000-8000-00000000b001","name":"Kiln"}}';
    private const VECTOR_3_V1 = '6772e49c625676723920ac0f24b1743de2a4af8be7a4d02c89aabf1875126589';

    public function testVector1MaintenanceStarted(): void
    {
        self::assertSame(
            self::VECTOR_1_V1,
            hash_hmac('sha256', self::TIMESTAMP . '.' . self::VECTOR_1_BODY, self::SECRET),
        );
    }

    public function testVector2MaintenanceEnded(): void
    {
        self::assertSame(
            self::VECTOR_2_V1,
            hash_hmac('sha256', self::TIMESTAMP . '.' . self::VECTOR_2_BODY, self::SECRET),
        );
    }

    public function testVector3Ping(): void
    {
        self::assertSame(
            self::VECTOR_3_V1,
            hash_hmac('sha256', self::TIMESTAMP . '.' . self::VECTOR_3_BODY, self::SECRET),
        );
    }

    /**
     * A single flipped character in the body must not still hash to the
     * recorded digest — otherwise the vectors above would be tautological.
     */
    public function testFlippingOneCharacterOfTheBodyChangesTheDigest(): void
    {
        $tampered = substr_replace(self::VECTOR_1_BODY, 'X', 40, 1);

        self::assertNotSame(
            self::VECTOR_1_V1,
            hash_hmac('sha256', self::TIMESTAMP . '.' . $tampered, self::SECRET),
        );
    }
}
