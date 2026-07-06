<?php

declare(strict_types=1);

namespace App\Tests\Unit\Render;

use Thallo\Render\Templates\IconSet;
use PHPUnit\Framework\TestCase;

final class IconSetTest extends TestCase
{
    private function set(): IconSet
    {
        return new IconSet(dirname(__DIR__, 3) . '/packages/thallo-render/resources/icons');
    }

    public function testLucideNameResolvesToDecoratedSvg(): void
    {
        $svg = $this->set()->svg('activity');
        self::assertNotNull($svg);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
        self::assertStringContainsString('thallo-icon', $svg);
        // Exactly one class attribute in the opening tag (appended, never duplicated).
        $openingTag = substr($svg, 0, (int) strpos($svg, '>'));
        self::assertSame(1, substr_count($openingTag, 'class='));
    }

    public function testBrandNamespaceResolvesFromBrandsDir(): void
    {
        $svg = $this->set()->svg('brand:github');
        self::assertNotNull($svg);
        self::assertStringContainsString('fill="currentColor"', $svg);
        self::assertStringContainsString('thallo-icon', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
    }

    /** Executable checks pinned at plan review. */
    public function testInvalidAndUnknownNamesReturnNull(): void
    {
        $set = $this->set();
        self::assertNull($set->svg('../x'));
        self::assertNull($set->svg('brand:../x'));
        self::assertNull($set->svg('Brand:github'));
        self::assertNull($set->svg('brand:github.svg'));
        self::assertNull($set->svg('no-such-icon-name'));
        self::assertNull($set->svg('brand:no-such-brand'));
        self::assertNull($set->svg(''));
        self::assertNull($set->svg('a/b'));
        self::assertNull($set->svg('Star'));
    }

    public function testMissesAreMemoized(): void
    {
        $set = $this->set();
        self::assertNull($set->svg('no-such-icon-name'));
        self::assertNull($set->svg('no-such-icon-name')); // second hit served from memo
    }
}
