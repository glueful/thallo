<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

/**
 * A complete app-theme fixture (visual builder spec §2.2): every theme must map the platform
 * vocabulary and list its stylesheets, so a test theme is the shipped default theme's
 * `theme.json` under another name, plus one stylesheet that exists.
 */
final class ThemeFixture
{
    private const DEFAULT_THEME_JSON = __DIR__ . '/../../packages/thallo-render/themes/default/theme.json';

    /**
     * The theme.json for a fixture theme named `$name`, with `$extra` merged on top.
     *
     * @param array<string,mixed> $extra
     */
    public static function json(string $name, array $extra = []): string
    {
        $base = json_decode((string) file_get_contents(self::DEFAULT_THEME_JSON), true);
        $json = ['name' => $name, 'version' => '1.0.0', 'menus' => ['main']]
            + $extra
            + ['vocabulary' => $base['vocabulary'], 'stylesheets' => ['assets/site.css']];
        return (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Write a loadable fixture theme at `$themeDir`: `theme.json`, `templates/` and
     * `assets/site.css` (created only when absent, so a test's own stylesheet wins).
     *
     * @param array<string,mixed> $extra
     */
    public static function write(string $themeDir, string $name, array $extra = []): void
    {
        @mkdir($themeDir . '/templates', 0755, true);
        @mkdir($themeDir . '/assets', 0755, true);
        if (!is_file($themeDir . '/assets/site.css')) {
            file_put_contents($themeDir . '/assets/site.css', ":root { --fixture: {$name}; }\n");
        }
        file_put_contents($themeDir . '/theme.json', self::json($name, $extra));
    }
}
