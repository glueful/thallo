<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Theme\ThemeDesign;

/**
 * Three typeface pairings were not enough to look like anyone in particular. There are more —
 * still system stacks, so they cost a visitor nothing — and a site may bring its OWN faces: a
 * woff2 for the text, one for the headings, or both.
 */
final class ThemeTypefacesTest extends TestCase
{
    public function testThereAreMorePairingsAndEachIsAStackThatEndsInAGenericFamily(): void
    {
        self::assertSame(
            ['sans', 'editorial', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system', 'custom'],
            ThemeDesign::FONTS,
        );
        foreach (['humanist', 'geometric', 'slab', 'mono', 'system'] as $font) {
            $css = ThemeDesign::css('round', $font, 'plain', 'slate');
            self::assertStringContainsString('--font-display:', $css, $font);
            self::assertMatchesRegularExpression(
                '~--font-(body|display):[^;}]*(sans-serif|serif|monospace)[;}]~',
                $css,
                $font,
            );
            self::assertStringNotContainsString('url(', $css, "{$font} downloads nothing");
        }
        // A slab is a headline face: the text stays the theme's own.
        self::assertStringNotContainsString('--font-body', ThemeDesign::css('round', 'slab', 'plain', 'slate'));
        self::assertStringContainsString('--font-body', ThemeDesign::css('round', 'humanist', 'plain', 'slate'));
    }

    public function testASitesOwnFacesAreDeclaredAndPutFirstInTheStack(): void
    {
        $css = ThemeDesign::css('round', 'custom', 'plain', 'slate', [
            'body' => '/v1/blobs/bodyfont0001',
            'display' => '/v1/blobs/headfont0001',
        ]);
        self::assertStringContainsString(
            '@font-face{font-family:"Site Body";src:url("/v1/blobs/bodyfont0001") format("woff2");'
            . 'font-weight:100 900;font-display:swap}',
            $css,
        );
        self::assertStringContainsString('@font-face{font-family:"Site Display";', $css);
        // A system stack stays behind it: what shows while the face loads, or if it fails.
        self::assertMatchesRegularExpression('~--font-body:"Site Body",system-ui,[^;}]*sans-serif~', $css);
        self::assertMatchesRegularExpression('~--font-display:"Site Display",system-ui,[^;}]*sans-serif~', $css);
    }

    public function testOneFaceAloneDoesWhatItCan(): void
    {
        // Only a text face: headings follow it, as they follow the body in the theme.
        $body = ThemeDesign::css('round', 'custom', 'plain', 'slate', ['body' => '/v1/blobs/bodyfont0001']);
        self::assertStringContainsString('--font-body:"Site Body"', $body);
        self::assertStringContainsString('--font-display:"Site Body"', $body);
        self::assertStringNotContainsString('Site Display', $body);

        // Only a heading face: the text is left alone.
        $display = ThemeDesign::css('round', 'custom', 'plain', 'slate', ['display' => '/v1/blobs/headfont0001']);
        self::assertStringContainsString('--font-display:"Site Display"', $display);
        self::assertStringNotContainsString('--font-body', $display);

        // "Custom" with nothing uploaded is the theme as it ships.
        self::assertSame('', ThemeDesign::css('round', 'custom', 'plain', 'slate', []));
        // And a face is only used by the custom choice.
        self::assertStringNotContainsString(
            'url(',
            ThemeDesign::css('round', 'serif', 'plain', 'slate', ['body' => '/v1/blobs/bodyfont0001']),
        );
    }

    public function testAUrlIsWrittenAsAStringAndCanNeverEndTheRule(): void
    {
        $css = ThemeDesign::css('round', 'custom', 'plain', 'slate', [
            'body' => 'https://cdn.test/f.woff2?a="b")}</style><script>x</script>',
        ]);
        self::assertStringNotContainsString('</style>', $css);
        self::assertStringNotContainsString('<script', $css);
        self::assertSame(1, substr_count($css, '@font-face'));
        self::assertSame(1, preg_match('~url\("([^"]*)"\)~', $css, $m), $css);
        self::assertStringNotContainsString('"', $m[1]);
    }

    public function testWhetherTheThemesOwnFaceIsStillUsedIsKnowable(): void
    {
        // The layout preloads the theme's face; preloading one nothing uses is a wasted download.
        self::assertTrue(ThemeDesign::usesThemeFace('sans', []));
        self::assertTrue(ThemeDesign::usesThemeFace('editorial', []), 'the text is still the theme face');
        self::assertTrue(ThemeDesign::usesThemeFace('slab', []));
        self::assertFalse(ThemeDesign::usesThemeFace('serif', []));
        self::assertFalse(ThemeDesign::usesThemeFace('system', []));
        self::assertFalse(ThemeDesign::usesThemeFace('custom', ['body' => '/x']));
        self::assertTrue(ThemeDesign::usesThemeFace('custom', ['display' => '/x']), 'only the headings changed');
        self::assertTrue(ThemeDesign::usesThemeFace('custom', []));
    }
}
