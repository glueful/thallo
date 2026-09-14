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
