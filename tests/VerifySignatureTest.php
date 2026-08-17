<?php

declare(strict_types=1);

namespace AllSystems\Laravel\Tests;

use AllSystems\Laravel\Http\Middleware\VerifySignature;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;

/**
 * Drives real HTTP through the registered route, exercising VerifySignature
 * as the only thing between an anonymous request and the (stub)
 * WebhookController — which is exactly the boundary it exists to guard.
 */
final class VerifySignatureTest extends TestCase
{
    private const PATH = '/allsystems/webhook';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        // Only the "secret not configured" test needs the secret unset;
        // every other test relies on TestCase's default being in effect.
        if ($this->name() === 'testUnsetSecretRejectsEvenAValidSignature') {
            $app->make(ConfigRepository::class)->set('allsystems.secret', null);
        }
    }

    public function testACorrectlySignedRequestReachesTheController(): void
    {
        $body = '{"event":"ping"}';
        $timestamp = time();

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($timestamp, $body), $body)
            ->assertOk()
        ;
    }

    public function testNoHeaderIsRejected(): void
    {
        $this->call('POST', self::PATH, [], [], [], $this->transformHeadersToServerVars([]), '{}')
            ->assertUnauthorized()
        ;
    }

    public function testHeaderWithOnlyTimestampIsRejected(): void
    {
        $headers = $this->transformHeadersToServerVars([
            'X-AllSystems-Signature' => 't=' . time(),
        ]);

        $this->call('POST', self::PATH, [], [], [], $headers, '{}')
            ->assertUnauthorized()
        ;
    }

    public function testHeaderWithOnlyV1IsRejected(): void
    {
        $headers = $this->transformHeadersToServerVars([
            'X-AllSystems-Signature' => 'v1=' . str_repeat('a', 64),
        ]);

        $this->call('POST', self::PATH, [], [], [], $headers, '{}')
            ->assertUnauthorized()
        ;
    }

    public function testV1ThatIsNotSixtyFourHexCharsIsRejected(): void
    {
        $headers = $this->transformHeadersToServerVars([
            'X-AllSystems-Signature' => 't=' . time() . ',v1=deadbeef',
        ]);

        $this->call('POST', self::PATH, [], [], [], $headers, '{}')
            ->assertUnauthorized()
        ;
    }

    /**
     * now - 301 is one second past the default 300s tolerance: stale.
     */
    public function testAStaleTimestampIsRejected(): void
    {
        $body = '{"event":"ping"}';
        $timestamp = time() - 301;

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($timestamp, $body), $body)
            ->assertUnauthorized()
        ;
    }

    /**
     * now + 301 is one second beyond tolerance in the other direction —
     * abs() must reject a future timestamp exactly as it rejects a stale one.
     */
    public function testAFutureTimestampIsRejected(): void
    {
        $body = '{"event":"ping"}';
        $timestamp = time() + 301;

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($timestamp, $body), $body)
            ->assertUnauthorized()
        ;
    }

    /**
     * now - 299 is one second INSIDE the 300s tolerance: the boundary case
     * that proves the check is abs(now - t) > tolerance (inclusive at the
     * edge), not some off-by-one variant that would reject this too.
     */
    public function testNowMinus299PassesTheToleranceBoundary(): void
    {
        $body = '{"event":"ping"}';
        $timestamp = time() - 299;

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($timestamp, $body), $body)
            ->assertOk()
        ;
    }

    public function testASignatureOverADifferentBodyIsRejected(): void
    {
        $timestamp = time();
        $headers = $this->signedHeaders($timestamp, '{"event":"ping"}');

        $this->call('POST', self::PATH, [], [], [], $headers, '{"event":"totally-different"}')
            ->assertUnauthorized()
        ;
    }

    public function testUnsetSecretRejectsEvenAValidSignature(): void
    {
        // The secret is unset via defineEnvironment() above for this test
        // only. Sign with TestCase::SECRET anyway — a syntactically valid
        // header — to prove the rejection is the missing config, not a
        // malformed request.
        $body = '{"event":"ping"}';
        $timestamp = time();

        $this->call('POST', self::PATH, [], [], [], $this->signedHeaders($timestamp, $body), $body)
            ->assertUnauthorized()
        ;
    }

    /**
     * The re-encode trap: this body has non-canonical JSON spacing (extra
     * space after each `:`). The signature is computed over these EXACT
     * bytes. If the middleware ever decoded and re-encoded the body instead
     * of hashing $request->getContent() verbatim, the digest it computed
     * would differ from this one and the request would be wrongly rejected.
     */
    public function testNonCanonicalJsonSpacingStillVerifiesBecauseTheRawBodyIsSigned(): void
    {
        $body = '{"event": "ping", "application": {"id": "01920000-0000-7000-8000-00000000b001"}}';
        $timestamp = time();
        $headers = $this->signedHeaders($timestamp, $body);

        $this->call('POST', self::PATH, [], [], [], $headers, $body)
            ->assertOk()
        ;
    }

    /** @return array<string, string> */
    private function signedHeaders(int $timestamp, string $body): array
    {
        $v1 = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return $this->transformHeadersToServerVars([
            VerifySignature::HEADER => "t={$timestamp},v1={$v1}",
        ]);
    }
}
