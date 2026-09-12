<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Delivery\Cursor;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testEncodeDecodeRoundTrips(): void
    {
        $key = ['sort' => '50', 'id' => 1234];
        $token = Cursor::encode($key);

        self::assertNotSame('', $token);
        // Opaque: base64url, no padding, no reserved chars.
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $token);
        self::assertSame($key, Cursor::decode($token));
    }

    public function testRoundTripsNullSortValue(): void
    {
        $key = ['sort' => null, 'id' => 7];
        self::assertSame($key, Cursor::decode(Cursor::encode($key)));
    }

    public function testTamperedTokenDecodesToNull(): void
    {
        self::assertNull(Cursor::decode('!!!not-base64!!!'));
        self::assertNull(Cursor::decode('garbage'));
        self::assertNull(Cursor::decode(''));
    }

    public function testDecodingValidBase64OfNonJsonIsNull(): void
    {
        $token = rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '=');
        self::assertNull(Cursor::decode($token));
    }

    public function testDecodingJsonMissingKeysIsNull(): void
    {
        $token = rtrim(strtr(base64_encode((string) json_encode(['foo' => 'bar'])), '+/', '-_'), '=');
        self::assertNull(Cursor::decode($token));
    }

    public function testDecodingNonIntegerIdIsNull(): void
    {
        $token = rtrim(strtr(base64_encode((string) json_encode(['sort' => 'x', 'id' => 'abc'])), '+/', '-_'), '=');
        self::assertNull(Cursor::decode($token));
    }
}
