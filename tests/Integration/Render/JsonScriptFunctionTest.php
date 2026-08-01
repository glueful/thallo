<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;

final class JsonScriptFunctionTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testEncodesJsonLdWithHexProtections(): void
    {
        $out = (string) $this->ext()->jsonScript(['@type' => 'Product', 'name' => 'A "B" & C']);
        self::assertStringContainsString('\\u0022B\\u0022', $out); // JSON_HEX_QUOT
        self::assertStringNotContainsString('"B"', $out);          // no bare quotes in values
        self::assertStringNotContainsString('&', $out);            // JSON_HEX_AMP → \\u0026
        // Round-trips to the same data.
        self::assertSame('A "B" & C', json_decode($out, true)['name']);
    }

    public function testScriptBreakoutIsUnrepresentable(): void
    {
        $out = (string) $this->ext()->jsonScript(['x' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $out);    // JSON_HEX_TAG
        self::assertStringNotContainsString('<script', $out);
    }

    public function testFailClosedOnUnencodableInput(): void
    {
        $this->expectException(\JsonException::class);
        $this->ext()->jsonScript(['bad' => "\xB1\x31"]); // invalid UTF-8
    }

    public function testReturnsMarkupSoAutoescapeDoesNotDoubleEncode(): void
    {
        self::assertInstanceOf(\Twig\Markup::class, $this->ext()->jsonScript([]));
    }
}
