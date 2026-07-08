<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/** Shadow-system plan Task 1: the elevation scale + overridable color/strength. */
final class ShadowTokensTest extends AppTestCase
{
    private function css(string $file): string
    {
        $path = $this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/' . $file;
        return (string) file_get_contents($path);
    }

    public function testScaleTokensDefinedWithOverridableColorAndStrength(): void
    {
        $site = $this->css('site.css');
        foreach (['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $level) {
            self::assertStringContainsString('--shadow-' . $level . ':', $site, "missing --shadow-{$level}");
        }
        // Overridable knobs + color-mix composition.
        self::assertStringContainsString('--shadow-color:', $site);
        self::assertStringContainsString('--shadow-strength:', $site);
        self::assertStringContainsString('color-mix(in srgb, var(--shadow-color)', $site);
        self::assertStringContainsString('var(--shadow-strength)', $site);
    }

    public function testDefaultShadowAliasesMd(): void
    {
        self::assertMatchesRegularExpression('/--shadow:\s*var\(--shadow-md\)\s*;/', $this->css('site.css'));
    }

    public function testDarkOverridesColorAndStrengthOnly(): void
    {
        $site = $this->css('site.css');
        $dark = substr($site, (int) strpos($site, 'html[data-theme="dark"]'));
        self::assertStringContainsString('--shadow-color: #000000', $dark);
        self::assertStringContainsString('--shadow-strength: 2.5', $dark);
        // Dark must NOT re-hardcode a raw multi-value --shadow literal (recomputes via vars).
        self::assertDoesNotMatchRegularExpression('/--shadow:\s*0 /', $dark);
    }

    public function testUtilityClassesExist(): void
    {
        $blocks = $this->css('blocks.css');
        foreach (['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $level) {
            self::assertStringContainsString(
                '.thallo-shadow-' . $level . ' {',
                $blocks,
                "missing .thallo-shadow-{$level}",
            );
        }
    }

    public function testNavOverlayUsesLg(): void
    {
        self::assertStringContainsString('box-shadow: var(--shadow-lg)', $this->css('navigation.css'));
    }

    public function testNoRawBoxShadowLiteralsRemain(): void
    {
        // Every box-shadow: declaration in component CSS must go through a token
        // (var(--shadow…)) or be `none` — the single-source-of-truth invariant.
        foreach (['blocks.css', 'navigation.css'] as $file) {
            foreach (explode("\n", $this->css($file)) as $line) {
                if (!preg_match('/(?<!-)box-shadow:\s*(.+?);/', $line, $m)) {
                    continue; // skips `transition: … box-shadow …` (no colon-value) and token defs
                }
                $val = trim($m[1]);
                self::assertTrue(
                    str_contains($val, 'var(--shadow') || $val === 'none',
                    "raw box-shadow in {$file}: {$val}",
                );
            }
        }
    }
}
