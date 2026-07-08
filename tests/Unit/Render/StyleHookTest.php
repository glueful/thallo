<?php

declare(strict_types=1);

namespace App\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\RenderContextExtension;

/** Style-block spec §4.3 / pin 7: the class-hook is never trusted raw. */
final class StyleHookTest extends TestCase
{
    public function testValidSingleTokenIsNamespaced(): void
    {
        self::assertSame(' thallo-style-promo', RenderContextExtension::sanitizeStyleHook('promo'));
    }

    public function testMultipleTokensEachNamespaced(): void
    {
        self::assertSame(
            ' thallo-style-promo thallo-style-dark-cta',
            RenderContextExtension::sanitizeStyleHook('promo dark-cta'),
        );
    }

    public function testPrefixIsIdempotent(): void
    {
        self::assertSame(' thallo-style-promo', RenderContextExtension::sanitizeStyleHook('thallo-style-promo'));
    }

    public function testMaliciousInputYieldsEmpty(): void
    {
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('"><script>alert(1)</script>'));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('a"onclick=b'));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook(''));
        self::assertSame('', RenderContextExtension::sanitizeStyleHook('   '));
    }

    public function testMixedGoodAndBadKeepsOnlyGood(): void
    {
        self::assertSame(' thallo-style-foo', RenderContextExtension::sanitizeStyleHook('foo bar">x'));
    }
}
