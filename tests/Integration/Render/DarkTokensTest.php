<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Dark mode is a token re-map keyed on html[data-theme="dark"] (color-mode spec §3.3),
 * the single source of truth the resolver stamps. The OS media query must NOT also
 * re-map :root, or a stored 'light' choice on an OS-dark machine would render dark.
 */
final class DarkTokensTest extends AppTestCase
{
    private function siteCss(): string
    {
        // The active theme falls through to the pack default (ThemeLocator §4 ladder);
        // its assets are served from the thallo-render package, not the empty app themes/.
        return (string) file_get_contents(
            $this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/site.css'
        );
    }

    public function testDarkThemeReMapsCoreTokens(): void
    {
        $css = $this->siteCss();
        self::assertStringContainsString('html[data-theme="dark"]', $css);
        foreach (['--bg', '--ink', '--muted', '--surface', '--line'] as $token) {
            self::assertMatchesRegularExpression(
                '/html\[data-theme="dark"\][^}]*' . preg_quote($token, '/') . '\s*:/s',
                $css,
                "dark theme must re-map {$token}",
            );
        }
    }

    public function testOsMediaQueryDoesNotAlsoReMapRoot(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root/s',
            $this->siteCss(),
            'prefers-color-scheme must not re-map :root — dark mode is keyed on data-theme',
        );
    }
}
