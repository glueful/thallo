<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Preview\PreviewToken;
use Thallo\Core\Content\Preview\PreviewTokenException;
use PHPUnit\Framework\TestCase;

final class PreviewTokenTest extends TestCase
{
    private const KEY = 'fixed-test-key-0123456789abcdef';
    private const OTHER_KEY = 'a-completely-different-key-9876543210';

    public function testMintProducesOpaqueDottedString(): void
    {
        $token = PreviewToken::mint('entry-1', 'en', null, 9999999999, self::KEY);

        self::assertNotSame('', $token);
        self::assertStringContainsString('.', $token);
        // payload + signature, both base64url, joined by a single dot.
        $parts = explode('.', $token);
        self::assertCount(2, $parts);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $parts[0]);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $parts[1]);
    }

    public function testVerifyRoundTripsBinding(): void
    {
        $exp = 9999999999;
        $token = PreviewToken::mint('entry-abc', 'fr', 'ver-xyz', $exp, self::KEY);

        $vo = PreviewToken::verify($token, self::KEY, 1000);

        self::assertSame('entry-abc', $vo->entryUuid);
        self::assertSame('fr', $vo->locale);
        self::assertSame('ver-xyz', $vo->versionUuid);
        self::assertSame($exp, $vo->expiresAt);
    }

    public function testNullVersionRoundTripsToNull(): void
    {
        $token = PreviewToken::mint('entry-1', 'en', null, 9999999999, self::KEY);
        $vo = PreviewToken::verify($token, self::KEY, 1000);

        self::assertNull($vo->versionUuid);
    }

    public function testNonNullVersionRoundTripsToThatUuid(): void
    {
        $token = PreviewToken::mint('entry-1', 'en', 'pinned-version-7', 9999999999, self::KEY);
        $vo = PreviewToken::verify($token, self::KEY, 1000);

        self::assertSame('pinned-version-7', $vo->versionUuid);
    }

    public function testTamperedPayloadRejectedAsInvalidSignature(): void
    {
        $token = PreviewToken::mint('entry-A', 'en', null, 9999999999, self::KEY);
        [$payload, $sig] = explode('.', $token, 2);

        // Flip a character in the payload (binding tamper: e.g. swap entry id).
        $flipped = $this->flipFirstChar($payload);
        $tampered = $flipped . '.' . $sig;

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($tampered, self::KEY, 1000);
    }

    public function testTamperedSignatureRejectedAsInvalidSignature(): void
    {
        $token = PreviewToken::mint('entry-A', 'en', null, 9999999999, self::KEY);
        [$payload, $sig] = explode('.', $token, 2);

        $tampered = $payload . '.' . $this->flipFirstChar($sig);

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($tampered, self::KEY, 1000);
    }

    public function testBindingCannotBeSwappedToAnotherEntry(): void
    {
        // A token minted for entry A must not be re-pointed at entry B
        // without breaking the signature.
        $tokenA = PreviewToken::mint('entry-A', 'en', null, 9999999999, self::KEY);
        $tokenB = PreviewToken::mint('entry-B', 'en', null, 9999999999, self::KEY);
        [$payloadB] = explode('.', $tokenB, 2);
        [, $sigA] = explode('.', $tokenA, 2);

        // Take entry-B's payload but entry-A's signature -> must be rejected.
        $forged = $payloadB . '.' . $sigA;

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($forged, self::KEY, 1000);
    }

    public function testDifferentKeyRejectedAsInvalidSignature(): void
    {
        $token = PreviewToken::mint('entry-1', 'en', null, 9999999999, self::KEY);

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($token, self::OTHER_KEY, 1000);
    }

    public function testNearMissSignatureRejected(): void
    {
        // A forged signature that differs by a single character (constant-time
        // hash_equals intent: not a substring/prefix match -> full reject).
        $token = PreviewToken::mint('entry-1', 'en', null, 9999999999, self::KEY);
        [$payload, $sig] = explode('.', $token, 2);

        // Change the last character of the signature only.
        $nearMiss = $payload . '.' . substr($sig, 0, -1) . ($sig[-1] === 'A' ? 'B' : 'A');

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($nearMiss, self::KEY, 1000);
    }

    public function testExpiredTokenRejectedAsExpiredAndDistinct(): void
    {
        // exp is in the past relative to now.
        $token = PreviewToken::mint('entry-1', 'en', null, 1000, self::KEY);

        try {
            PreviewToken::verify($token, self::KEY, 2000);
            self::fail('Expected PreviewTokenException for an expired token');
        } catch (PreviewTokenException $e) {
            // Distinct from invalid-signature so the controller can map 410 vs 403.
            self::assertSame(PreviewTokenException::EXPIRED, $e->getCode());
            self::assertNotSame(PreviewTokenException::INVALID_SIGNATURE, $e->getCode());
            self::assertNotSame(PreviewTokenException::MALFORMED, $e->getCode());
            self::assertTrue($e->isExpired());
        }
    }

    public function testTamperedExpiryCannotExtendLifetime(): void
    {
        // The highest-value binding guard: exp is INSIDE the HMAC-protected
        // payload, so an attacker cannot rewrite an expired token's exp to the
        // future and reuse the original signature. Such a forgery must be
        // rejected as INVALID_SIGNATURE (not silently accepted, not "expired").
        $token = PreviewToken::mint('entry-1', 'en', null, 1000, self::KEY); // already expired
        [$payload, $sig] = explode('.', $token, 2);

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $data['exp'] = 9999999999; // attacker tries to extend lifetime
        $forgedPayload = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);

        PreviewToken::verify($forgedPayload . '.' . $sig, self::KEY, 2000);
    }

    public function testExpiryBoundaryNotYetExpired(): void
    {
        // exp == now must still be valid (exp < now is the reject condition).
        $token = PreviewToken::mint('entry-1', 'en', null, 5000, self::KEY);
        $vo = PreviewToken::verify($token, self::KEY, 5000);

        self::assertSame('entry-1', $vo->entryUuid);
    }

    public function testMalformedTokenNoDotRejected(): void
    {
        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::MALFORMED);

        PreviewToken::verify('not-a-token-without-a-dot', self::KEY, 1000);
    }

    public function testMalformedEmptyTokenRejected(): void
    {
        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::MALFORMED);

        PreviewToken::verify('', self::KEY, 1000);
    }

    public function testMalformedGarbagePayloadRejected(): void
    {
        // A well-formed two-part token whose signature matches but whose
        // payload is not valid JSON of the expected shape -> malformed.
        $payload = rtrim(strtr(base64_encode('this is not json'), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, self::KEY, true)), '+/', '-_'), '=');
        $token = $payload . '.' . $sig;

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::MALFORMED);

        PreviewToken::verify($token, self::KEY, 1000);
    }

    public function testMalformedMissingRequiredFieldRejected(): void
    {
        // Valid signature over JSON missing the required 'e' field -> malformed.
        $payload = rtrim(strtr(base64_encode(json_encode(['l' => 'en', 'exp' => 9999999999])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, self::KEY, true)), '+/', '-_'), '=');
        $token = $payload . '.' . $sig;

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::MALFORMED);

        PreviewToken::verify($token, self::KEY, 1000);
    }

    private function flipFirstChar(string $s): string
    {
        $first = $s[0];
        $replacement = $first === 'A' ? 'B' : 'A';

        return $replacement . substr($s, 1);
    }
}
