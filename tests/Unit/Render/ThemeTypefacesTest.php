<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Theme\ThemeDesign;

/**
 * Three typeface pairings were not enough to look like anyone in particular. There are more —
 * still system stacks, so they cost a visitor nothing — and Custom takes the Text and Headings from
 * the font library: built-ins, or the site's own uploaded families (block typeface spec §2.8).
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

    public function testCustomSetsEachRolesStackAndSynthesis(): void
    {
        $text = ['stack' => '"thallo-font-Tx3dE5fG7hJ9",system-ui,sans-serif', 'synthesis' => 'style'];
        $headings = ['stack' => '"Iowan Old Style",serif', 'synthesis' => 'weight style'];
        $css = ThemeDesign::css('round', 'custom', 'plain', 'slate', $text, $headings);
        self::assertSame(
            ':root{--font-body:"thallo-font-Tx3dE5fG7hJ9",system-ui,sans-serif;--font-synthesis-body:style;'
            . '--font-display:"Iowan Old Style",serif;--font-synthesis-display:weight style}',
            $css,
        );
        self::assertStringNotContainsString('@font-face', $css, 'faces are the fonts stylesheet\'s');
    }

    public function testOneRoleAloneDoesWhatItCan(): void
    {
        $text = ['stack' => '"thallo-font-Tx3dE5fG7hJ9",system-ui,sans-serif', 'synthesis' => 'style'];
        // Only a Text family: headings follow it, as they follow the body in the theme.
        $body = ThemeDesign::css('round', 'custom', 'plain', 'slate', $text);
        self::assertStringContainsString('--font-display:"thallo-font-Tx3dE5fG7hJ9"', $body);
        self::assertStringContainsString('--font-synthesis-display:style', $body);

        // Only a Headings family: the text is left alone.
        $display = ThemeDesign::css('round', 'custom', 'plain', 'slate', null, $text);
        self::assertStringContainsString('--font-display:"thallo-font-Tx3dE5fG7hJ9"', $display);
        self::assertStringNotContainsString('--font-body', $display);

        // "Custom" with no family is the theme as it ships.
        self::assertSame('', ThemeDesign::css('round', 'custom', 'plain', 'slate'));
        // And families are only used by the custom choice.
        self::assertStringNotContainsString('thallo-font', ThemeDesign::css('round', 'serif', 'plain', 'slate', $text));
    }

    public function testWhetherTheThemesOwnFaceIsStillUsedIsKnowable(): void
    {
        // The layout preloads the theme's face; preloading one nothing uses is a wasted download.
        $family = ['stack' => '"x",serif', 'synthesis' => 'style'];
        self::assertTrue(ThemeDesign::usesThemeFace('sans'));
        self::assertTrue(ThemeDesign::usesThemeFace('editorial'), 'the text is still the theme face');
        self::assertTrue(ThemeDesign::usesThemeFace('slab'));
        self::assertFalse(ThemeDesign::usesThemeFace('serif'));
        self::assertFalse(ThemeDesign::usesThemeFace('system'));
        self::assertFalse(ThemeDesign::usesThemeFace('custom', $family));
        self::assertTrue(ThemeDesign::usesThemeFace('custom'), 'no Text family: the theme face');
    }

    public function testAFamilyIdIsAnIdAndNothingElse(): void
    {
        foreach (['theme', 'serif', 'system', 'Tx3dE5fG7hJ9'] as $id) {
            self::assertSame($id, ThemeDesign::normalizeFamily($id));
        }
        foreach (['inherit', 'reset', 'Tx3dE5fG7hJ9x', '../etc', 'fontbody0001x', ''] as $junk) {
            self::assertNull(ThemeDesign::normalizeFamily($junk), $junk);
        }
    }
}
