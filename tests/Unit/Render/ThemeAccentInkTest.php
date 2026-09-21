<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;

/**
 * `--accent-ink` is the label ON the accent, and since a site's accent may be its own brand colour
 * it is not always white: on a yellow brand it is black. The default theme also used it as
 * "light text" on fills that are NOT the accent — the dark hero, the solid pricing plan, captions
 * over pictures — where a black ink would make the text vanish. Each of those now reads the token
 * that is true of ITS fill: the page ground on an ink fill (right in both colour modes), and the
 * fixed `--on-media` over pictures and scrims.
 */
final class ThemeAccentInkTest extends TestCase
{
    /** @return list<array{file:string,selector:string,body:string}> */
    private static function rules(): array
    {
        $dir = \dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets';
        $rules = [];
        foreach (glob($dir . '/*.css') ?: [] as $file) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $rules[] = ['file' => basename($file), 'selector' => trim($m[1]), 'body' => $m[2]];
            }
        }
        return $rules;
    }

    public function testTheAccentsInkOnlyColoursWhatSitsOnTheAccent(): void
    {
        $offenders = [];
        foreach (self::rules() as $rule) {
            if (preg_match('~(?<![-\w])color\s*:[^;]*var\(--accent-ink\)~', $rule['body']) !== 1) {
                continue;
            }
            $onAccent = preg_match('~background(?:-color)?\s*:[^;]*var\(--accent\)~', $rule['body']) === 1;
            // An author's explicit choice of the "accent contrast" colour token is theirs to make.
            $chosen = str_contains($rule['selector'], 'accent-contrast');
            if (!$onAccent && !$chosen) {
                $offenders[] = "{$rule['file']}: {$rule['selector']}";
            }
        }
        self::assertSame([], $offenders, 'these use --accent-ink on a fill that is not the accent');
    }

    public function testTextOverPicturesHasATokenOfItsOwnThatNoSettingChanges(): void
    {
        $site = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/site.css',
        );
        self::assertMatchesRegularExpression('~--on-media:\s*#ffffff;~', $site);
        // Declared once, in :root only: dark mode and the appearance settings leave it alone.
        self::assertSame(1, substr_count($site, '--on-media:'));
    }
}
