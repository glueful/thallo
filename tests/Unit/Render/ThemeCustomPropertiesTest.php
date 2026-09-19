<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;

/**
 * `var(--x)` with no fallback, where `--x` is never defined, makes the WHOLE declaration invalid
 * at computed-value time: the property silently takes its initial value. Nothing warns. The
 * feature block's number badge, the tabs' pill strip and their labels all read `--radius-md` or
 * `--radius-sm`, which the theme never defined — so they shipped square while the stylesheet said
 * rounded. This holds the class of bug: the default theme defines every custom property it reads
 * without a fallback.
 */
final class ThemeCustomPropertiesTest extends TestCase
{
    /** @return array<string,string> file => css, comments removed */
    private function stylesheets(string $glob): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3) . $glob) ?: [] as $file) {
            $out[basename($file)] = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
        }
        self::assertNotSame([], $out, $glob);
        return $out;
    }

    public function testEveryCustomPropertyReadWithoutAFallbackIsDefined(): void
    {
        $theme = $this->stylesheets('/packages/thallo-render/themes/default/assets/*.css');
        // Defined by the theme itself, or by the stylesheets the render pack ships beside it.
        $defined = [];
        foreach ($theme + $this->stylesheets('/packages/thallo-render/assets/*/*.css') as $css) {
            preg_match_all('~(--[a-zA-Z0-9_-]+)\s*:~', $css, $m);
            $defined += array_flip($m[1]);
        }

        $undefined = [];
        foreach ($theme as $file => $css) {
            preg_match_all('~var\(\s*(--[a-zA-Z0-9_-]+)\s*\)~', $css, $m);
            foreach (array_unique($m[1]) as $name) {
                // `--t-*` are the compiled settings artifact's (StyleCompiler), not the theme's.
                if (!isset($defined[$name]) && !str_starts_with($name, '--t-')) {
                    $undefined[] = "{$name} in {$file}";
                }
            }
        }

        self::assertSame(
            [],
            $undefined,
            'read with no fallback and never defined — the declaration is invalid and silently does nothing',
        );
    }

    public function testTheRadiusScaleTheBlocksReadMatchesTheVocabulary(): void
    {
        // The Style tab's radius tokens resolve through theme.json; a block's own default corners
        // read the same scale, so "md" on a badge is what "md" in the Style tab gives.
        $site = $this->stylesheets('/packages/thallo-render/themes/default/assets/site.css')['site.css'];
        $tokens = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/theme.json',
        ), true, 512, JSON_THROW_ON_ERROR);
        $values = $tokens['vocabulary'];

        self::assertSame('6px', $values['radius.sm'] ?? null);
        self::assertSame('var(--radius)', $values['radius.md'] ?? null);
        self::assertMatchesRegularExpression('~--radius-sm:\s*6px;~', $site);
        self::assertMatchesRegularExpression('~--radius-md:\s*var\(--radius\);~', $site);
    }
}
