<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * Default-theme-font spec §6: the font payload's visibility gate — separate from the
 * runtime's 12KB gzip budget. Raw file size (woff2 is already Brotli-compressed
 * internally, so gzip would be noise). Growth is a conscious decision: headroom exists
 * for the latin subsets, not for a stealth second family.
 */
final class FontPayloadBudgetTest extends TestCase
{
    private const FONTS_DIR = __DIR__ . '/../../../packages/thallo-render/themes/default/assets/fonts';

    public function testShippedFontsExistAndStayWithinTheRawByteBudget(): void
    {
        $roman = self::FONTS_DIR . '/figtree-roman-latin.woff2';
        $italic = self::FONTS_DIR . '/figtree-italic-latin.woff2';
        self::assertFileExists($roman);
        self::assertFileExists($italic);
        self::assertFileExists(self::FONTS_DIR . '/OFL.txt');
        self::assertFileExists(self::FONTS_DIR . '/PROVENANCE.md');

        $total = (int) filesize($roman) + (int) filesize($italic);
        self::assertLessThanOrEqual(
            131_072,
            $total,
            "Shipped fonts total {$total} raw bytes against a 128KB budget. Growth is "
            . 'fine when it is subset/coverage weight (raise the budget here, with '
            . 'reasoning in the default-theme-font spec §6); a second family belongs to '
            . 'the theme-presets track, not this budget.',
        );
    }
}
