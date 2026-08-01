<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.4: the style wrapper renders a scoped skin + class hook. */
final class StyleBlockRenderTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testSkinAndHookAndChildrenRender(): void
    {
        $out = $this->render([[
            'id' => 's1', 'type' => 'style',
            'data' => [
                'accent' => 'rose', 'neutral' => 'zinc', 'class_hook' => 'promo',
                'content' => [['id' => 'r1', 'type' => 'rich_text', 'data' => ['body' => '<p>hi</p>']]],
            ],
        ]]);
        self::assertStringContainsString(
            'class="thallo-block thallo-block-style thallo-skin-rose-zinc thallo-style-promo"',
            $out,
        );
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertSame(1, substr_count($out, '<style>'));         // exactly one skin style
        self::assertStringContainsString('thallo-block-style__inner', $out);
        self::assertStringContainsString('hi', $out);               // child rendered
        // P1: __inner is the first element child (canvas host), the <style> comes AFTER.
        self::assertLessThan(strpos($out, '<style>'), strpos($out, 'thallo-block-style__inner'));
    }

    public function testNoReskinStillWrapsAndAppliesHook(): void
    {
        $out = $this->render([[
            'id' => 's2', 'type' => 'style',
            'data' => ['class_hook' => 'plain', 'content' => []],
        ]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style thallo-style-plain"', $out);
        self::assertStringNotContainsString('<style>', $out);       // nothing to skin
    }

    public function testEmptyDataRendersCleanWrapper(): void
    {
        $out = $this->render([['id' => 's3', 'type' => 'style', 'data' => []]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style"', $out);
        self::assertStringNotContainsString('<style>', $out);
    }

    public function testNestedStyleBlocksEachEmitTheirSkin(): void
    {
        $out = $this->render([[
            'id' => 'o', 'type' => 'style',
            'data' => ['accent' => 'rose', 'content' => [[
                'id' => 'i', 'type' => 'style',
                'data' => ['neutral' => 'zinc', 'content' => []],
            ]]],
        ]]);
        self::assertStringContainsString('thallo-skin-rose-none', $out);
        self::assertStringContainsString('thallo-skin-none-zinc', $out);
        self::assertSame(2, substr_count($out, '<style>'));
    }

    public function testShadowSpacingAndInlineVarsRender(): void
    {
        $out = $this->render([[
            'id' => 'sp1', 'type' => 'style',
            'data' => [
                'shadow' => 'lg', 'shadow_color' => '#06b6d4', 'shadow_opacity' => 50,
                'padding' => 'medium', 'margin' => 'small', 'content' => [],
            ],
        ]]);
        self::assertStringContainsString('thallo-shadow-lg', $out);
        self::assertStringContainsString('thallo-block-style--pad-medium', $out);
        self::assertStringContainsString('thallo-block-style--mar-small', $out);
        self::assertStringContainsString('--shadow-color: #06b6d4', $out);
        self::assertStringContainsString('--shadow-strength: 0.5', $out);
    }

    public function testShadowDefaultsToNoneAndOmitsInlineVars(): void
    {
        $out = $this->render([['id' => 'sp2', 'type' => 'style', 'data' => ['content' => []]]]);
        self::assertStringNotContainsString('thallo-shadow-', $out);
        self::assertStringNotContainsString('--shadow-color', $out);
        self::assertStringNotContainsString('style="', $out);          // no inline vars when nothing set
    }

    public function testUnknownShadowEnumDegradesToNoModifier(): void
    {
        $out = $this->render([['id' => 'sp3', 'type' => 'style', 'data' => ['shadow' => 'banana', 'content' => []]]]);
        self::assertStringNotContainsString('thallo-shadow-banana', $out);
        self::assertStringNotContainsString('thallo-shadow-', $out);   // allowlist guard, no bogus class
    }

    /**
     * Render-time trust boundary: stale/malicious shadow_color and shadow_opacity
     * (values a DB row can hold regardless of admin validation) must never reach the
     * style attribute. A non-hex color and a non-numeric opacity are both dropped —
     * the shadow depth class still applies, but no inline var is emitted.
     */
    public function testMaliciousShadowColorAndOpacityAreDropped(): void
    {
        $out = $this->render([[
            'id' => 'sp4', 'type' => 'style',
            'data' => [
                'shadow' => 'md',
                'shadow_color' => '#06b6d4; background: url(//evil)',  // CSS-injection attempt
                'shadow_opacity' => '50); }',                          // non-numeric
                'content' => [],
            ],
        ]]);
        self::assertStringContainsString('thallo-shadow-md', $out);       // depth still applies
        self::assertStringNotContainsString('--shadow-color', $out);      // non-hex rejected
        self::assertStringNotContainsString('--shadow-strength', $out);   // non-numeric rejected
        self::assertStringNotContainsString('background: url', $out);     // no injected declaration
        self::assertStringNotContainsString('evil', $out);
    }

    public function testShadowOpacityIsClampedToTheAllowedRange(): void
    {
        // 999 → clamp to 200 → strength 2.
        $high = $this->render([['id' => 'sp5', 'type' => 'style',
            'data' => ['shadow' => 'md', 'shadow_opacity' => 999, 'content' => []]]]);
        self::assertStringContainsString('--shadow-strength: 2', $high);

        // Gate-audit amendment (task 7): the |matches regex this used to run
        // ("/^[0-9]+(\.[0-9]+)?$/") matched non-negative numbers only, so a bare
        // negative was dropped entirely. Its replacement, the numeric_clamp filter, is
        // gated on is_numeric() (which accepts negatives) and clamps into range — a
        // negative opacity is now numeric input clamped to the 0 floor, not a
        // non-numeric value to discard. See AllowlistedFunctionBoundsTest's
        // numeric_clamp('-5', 0, 200) === 0.0 pin for the helper-level contract.
        $neg = $this->render([['id' => 'sp6', 'type' => 'style',
            'data' => ['shadow' => 'md', 'shadow_opacity' => -5, 'content' => []]]]);
        self::assertStringContainsString('--shadow-strength: 0', $neg);
    }
}
