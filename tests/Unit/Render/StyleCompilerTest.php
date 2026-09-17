<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\CompiledStyleArtifacts;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeVocabulary;
use Thallo\Render\ThemeLocator;

/** Visual builder spec §2.4: the compiled style artifact is a pure function of the vocabulary. */
final class StyleCompilerTest extends TestCase
{
    private const DEFAULT_THEME = __DIR__ . '/../../../packages/thallo-render/themes/default';

    private function vocabulary(array $override = []): ThemeVocabulary
    {
        $json = json_decode((string) file_get_contents(self::DEFAULT_THEME . '/theme.json'), true);
        $json['vocabulary'] = $override + $json['vocabulary'];
        return ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
    }

    public function testClassNamesAreTheOnePlaceASettingBecomesAClass(): void
    {
        self::assertSame('t-pt-lg', ClassNames::for('spacing.padding.top', 'spacing.lg'));
        self::assertSame('md:t-pt-lg', ClassNames::for('spacing.padding.top', 'spacing.lg', 'md'));
        self::assertSame('.md\\:t-pt-lg', ClassNames::selector('md:t-pt-lg'));
        self::assertSame('lg:t-fg-reset', ClassNames::reset('colors.text', 'lg'));
        self::assertSame('t-vis-hidden', ClassNames::for('visibility', 'hidden'));
        foreach (array_keys(StyleSchema::properties()) as $path) {
            self::assertArrayHasKey($path, ClassNames::STEMS, "every managed property has a class stem: {$path}");
        }
    }

    public function testTheArtifactIsDeterministicLayeredAndOrderedBaseMdLg(): void
    {
        $css = StyleCompiler::compile($this->vocabulary());

        self::assertSame($css, StyleCompiler::compile($this->vocabulary()), 'deterministic');
        self::assertStringStartsWith("@layer settings {\n:root {\n", $css);
        self::assertStringContainsString('--t-spacing-lg: var(--space-4);', $css);
        self::assertStringContainsString('--t-color-accent: var(--accent);', $css);
        self::assertStringContainsString('.t-pt-lg { padding-top: var(--t-spacing-lg); }', $css);
        self::assertStringContainsString('.md\\:t-pt-lg { padding-top: var(--t-spacing-lg); }', $css);
        self::assertStringContainsString('.lg\\:t-pt-reset { padding-top: revert-layer; }', $css);
        self::assertStringContainsString('.t-w-full { max-width: none; width: 100%; }', $css);
        self::assertStringContainsString('.t-w-content { max-width: var(--t-width-content); }', $css);
        self::assertStringContainsString('.t-w-reset { max-width: revert-layer; width: revert-layer; }', $css);
        self::assertStringContainsString('.t-content-center { justify-content: center; }', $css);
        // The surface colour owns the whole background: a theme gradient (a background-image)
        // yields to it, so it is the shorthand, never background-color alone.
        self::assertStringContainsString('.t-bg-transparent { background: var(--t-color-transparent); }', $css);
        self::assertStringContainsString('.t-bg-reset { background: revert-layer; }', $css);
        self::assertStringNotContainsString('background-color', $css);
        self::assertStringContainsString('.t-self-end { margin-inline: auto 0; }', $css);
        self::assertStringContainsString('.t-vis-hidden { display: none; }', $css);
        self::assertStringContainsString('.md\\:t-vis-visible { display: revert-layer; }', $css);
        self::assertStringContainsString('.t-weight-semibold { font-weight: 600; }', $css);
        self::assertStringContainsString('.t-bw-thin { border-width: 1px; }', $css);
        self::assertStringContainsString('.t-radius-full { border-radius: var(--t-radius-full); }', $css);
        self::assertStringNotContainsString('.md\\:t-radius', $css, 'non-responsive: no breakpoint variants');
        self::assertStringNotContainsString('!important', $css);

        $base = strpos($css, '.t-pt-lg {');
        $md = strpos($css, '@media (min-width: 768px)');
        $lg = strpos($css, '@media (min-width: 1024px)');
        self::assertTrue($base < $md && $md < $lg, 'base, then md, then lg');
        self::assertStringEndsWith("}\n}\n", $css);
    }

    public function testEveryPropertyAndTokenHasARule(): void
    {
        $css = StyleCompiler::compile($this->vocabulary());
        foreach (StyleSchema::properties() as $path => $def) {
            $domain = $def->tokenDomain;
            $values = $domain !== null
                ? array_map(static fn (string $n): string => "{$domain}.{$n}", Vocabulary::names($domain))
                : ($def->choices ?? []);
            foreach ($values as $value) {
                $selector = ClassNames::selector(ClassNames::for($path, $value));
                self::assertStringContainsString($selector . ' {', $css, "{$path} {$value}");
            }
            $reset = ClassNames::selector(ClassNames::reset($path));
            self::assertStringContainsString($reset . ' {', $css, "{$path} reset");
        }
    }

    public function testLayoutUtilitiesCompileToTheirDeclarations(): void
    {
        // Container-layout plan, Task 1.1: one declaration per layout utility.
        $css = StyleCompiler::compile($this->vocabulary());
        $rule = static function (string $class) use ($css): string {
            $selector = preg_quote(ClassNames::selector($class), '~');
            self::assertMatchesRegularExpression("~{$selector} \{~", $css, $class);
            preg_match("~{$selector} \{([^}]*)\}~", $css, $m);
            return trim($m[1] ?? '');
        };

        self::assertSame('display: grid;', $rule('t-display-grid'));
        self::assertSame('flex-direction: column-reverse;', $rule('t-dir-column-reverse'));
        self::assertSame('flex-wrap: wrap;', $rule('t-wrap-wrap'));
        self::assertSame('align-items: flex-start;', $rule('t-items-start'));
        self::assertSame('justify-content: space-between;', $rule('t-content-between'));
        self::assertSame('justify-content: flex-start;', $rule('t-content-start'));
        self::assertSame(
            'grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);',
            $rule('t-cols-1-2'),
        );
        self::assertSame('grid-template-columns: repeat(12, minmax(0, 1fr));', $rule('t-cols-12'));
        // The default track state: a container that declares no track count.
        self::assertSame('grid-template-columns: none;', $rule('t-cols-auto'));
        self::assertSame('column-gap: var(--t-spacing-lg);', $rule('t-gapx-lg'));
        self::assertSame('row-gap: var(--t-spacing-md);', $rule('t-gapy-md'));
        self::assertSame('overflow: hidden;', $rule('t-overflow-hidden'));
        self::assertSame('flex-basis: 33.333%;', $rule('t-basis-1-3'));
        self::assertSame('flex-grow: 1;', $rule('t-grow-1'));
        self::assertSame('flex-shrink: 0;', $rule('t-shrink-0'));
        self::assertSame('align-self: center;', $rule('t-aself-center'));

        // Content width carries the default gutter with it, so an absent gutter resolves to the
        // width's default and never to an inherited one (spec §3.4).
        self::assertSame(
            'max-width: var(--t-width-container); margin-inline: auto; '
                . '--thallo-default-gutter: var(--t-spacing-lg);',
            $rule('t-cw-container'),
        );
        self::assertSame(
            'max-width: none; margin-inline: auto; --thallo-default-gutter: 0px;',
            $rule('t-cw-full'),
        );
        self::assertSame(
            'max-width: revert-layer; margin-inline: revert-layer; '
                . '--thallo-default-gutter: revert-layer;',
            $rule('t-cw-reset'),
        );
        self::assertSame('padding-inline: var(--t-spacing-xl);', $rule('t-gutter-xl'));
        self::assertSame('padding-inline: revert-layer;', $rule('t-gutter-reset'));

        // Min height never writes display: managed visibility owns that (spec §3.5 / review).
        self::assertSame('min-height: 50vh; --thallo-root-layout: flex;', $rule('t-minh-half'));
        self::assertSame('min-height: auto; --thallo-root-layout: block;', $rule('t-minh-auto'));
        self::assertSame(
            'min-height: revert-layer; --thallo-root-layout: revert-layer;',
            $rule('t-minh-reset'),
        );
        self::assertStringNotContainsString('t-minh-half { min-height: 50vh; display', $css);
    }

    public function testSpanIsPairedWithItsParentsTrackCountAtEveryBreakpoint(): void
    {
        // Container-layout spec §3.7 and the plan's review: a span is never a single-class rule,
        // so a base clamp cannot outrank a later breakpoint's span.
        $css = StyleCompiler::compile($this->vocabulary());
        // No rule whose whole selector is the span class: every span rule starts with its
        // parent's track class, at the same breakpoint.
        self::assertDoesNotMatchRegularExpression('~^\.t-span-2 \{~m', $css);
        self::assertDoesNotMatchRegularExpression('~^\.md\\\\:t-span-2 \{~m', $css);

        $pair = static function (string $cols, string $span) use ($css): string {
            $selector = preg_quote(ClassNames::selector($cols) . ' > ' . ClassNames::selector($span), '~');
            // Each pair is written in two forms, the plain one first and comma-terminated.
            self::assertMatchesRegularExpression("~{$selector}[ ,]~", $css, "{$cols} > {$span}");
            preg_match("~{$selector}[^{]*\{([^}]*)\}~", $css, $m);
            return trim($m[1] ?? '');
        };

        self::assertSame('grid-column: span 2;', $pair('md:t-cols-4', 'md:t-span-2'));
        self::assertSame('grid-column: 1 / -1;', $pair('md:t-cols-2', 'md:t-span-3'));
        self::assertSame('grid-column: 1 / -1;', $pair('md:t-cols-auto', 'md:t-span-2'));
        self::assertSame('grid-column: 1 / -1;', $pair('md:t-cols-reset', 'md:t-span-2'));
        self::assertSame('grid-column: span 1;', $pair('md:t-cols-reset', 'md:t-span-1'));
        self::assertSame('grid-column: 1 / -1;', $pair('lg:t-cols-3', 'lg:t-span-full'));
        // A reset span follows the contract's reset semantics, not a hard-coded default.
        self::assertSame('grid-column: revert-layer;', $pair('lg:t-cols-3', 'lg:t-span-reset'));
        // A ratio preset counts its parts: three tracks, so a span of three fits.
        self::assertSame('grid-column: span 3;', $pair('t-cols-1-2-1', 't-span-3'));

        // Every pair also matches through the stage's annotation wrapper.
        self::assertStringContainsString(
            ClassNames::selector('md:t-cols-4') . ' > .thallo-preview-block > '
                . ClassNames::selector('md:t-span-2'),
            $css,
        );
    }

    public function testTheHashFollowsTheVocabularyAndTheCompilerVersion(): void
    {
        $a = StyleCompiler::hash($this->vocabulary());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $a);
        self::assertSame($a, StyleCompiler::hash($this->vocabulary()));
        self::assertNotSame($a, StyleCompiler::hash($this->vocabulary(['spacing.lg' => '2rem'])));
        self::assertStringContainsString('"compiler":' . StyleCompiler::VERSION, json_encode([
            'compiler' => StyleCompiler::VERSION,
        ]), 'the version is part of the hash input');
    }

    public function testArtifactsArePublishedByHashReadBackAndPruned(): void
    {
        $dir = sys_get_temp_dir() . '/thallo-compiled-' . uniqid('', true);
        try {
            $artifacts = new CompiledStyleArtifacts($dir);
            $theme = new ThemeLocator('default', $dir . '/no-app-themes');
            $artifact = $artifacts->forTheme($theme);
            self::assertFileExists($dir . '/settings-' . $artifact['hash'] . '.css');
            self::assertSame($artifact['css'], $artifacts->read($artifact['hash']));
            self::assertNull($artifacts->read('0000000000000000'));
            $file = 'settings-' . $artifact['hash'] . '.css';
            self::assertSame($artifact['hash'], CompiledStyleArtifacts::hashFromFileName($file));

            // Retention: old artifacts beyond the newest three are dropped once older than a day.
            for ($i = 0; $i < 4; $i++) {
                $old = $dir . "/settings-000000000000000{$i}.css";
                file_put_contents($old, '');
                touch($old, time() - 2 * CompiledStyleArtifacts::RETAIN_SECONDS - $i);
            }
            (new CompiledStyleArtifacts($dir))->forTheme($theme);
            $left = array_map('basename', glob($dir . '/settings-*.css') ?: []);
            self::assertCount(CompiledStyleArtifacts::RETAIN_NEWEST, $left);
            self::assertContains('settings-' . $artifact['hash'] . '.css', $left, 'the current artifact is kept');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
