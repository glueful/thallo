<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\FontStacks;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeVocabulary;

/**
 * The built-in typeface utilities live in the theme's compiled settings artifact (block typeface spec
 * §2.1, §3.3): each sets the family and its synthesis policy; `theme` uses the theme's declared face.
 */
final class StyleCompilerFontTest extends TestCase
{
    private const DEFAULT_THEME = __DIR__ . '/../../../packages/thallo-render/themes/default';
    private const FIGTREE = '"Figtree", "Figtree Fallback", system-ui, -apple-system, "Segoe UI", sans-serif';

    /** @param array<string,mixed>|null $face null drops the theme's face; otherwise replaces it */
    private function vocabulary(?array $face = []): ThemeVocabulary
    {
        $json = json_decode((string) file_get_contents(self::DEFAULT_THEME . '/theme.json'), true);
        if ($face === null) {
            unset($json['face']);
        } elseif ($face !== []) {
            $json['face'] = $face;
        }
        return ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
    }

    private function settingsLayer(string $css): string
    {
        self::assertStringStartsWith("@layer settings {\n", $css);
        return $css;
    }

    public function testEachBuiltInSetsItsStackAndTheBrowsersSynthesis(): void
    {
        $css = $this->settingsLayer(StyleCompiler::compile($this->vocabulary()));
        foreach (['serif', 'humanist', 'geometric', 'slab', 'mono', 'system'] as $id) {
            self::assertStringContainsString(
                '.t-font-' . $id . ' { font-family: ' . FontStacks::named($id) . '; font-synthesis: weight style; }',
                $css,
                $id,
            );
        }
        self::assertStringContainsString(
            '.t-font-serif { font-family: "Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif;'
                . ' font-synthesis: weight style; }',
            $css,
        );
    }

    public function testThemeUsesTheDeclaredFace(): void
    {
        $css = StyleCompiler::compile($this->vocabulary());
        self::assertStringContainsString(
            '.t-font-theme { font-family: ' . self::FIGTREE . '; font-synthesis: weight style; }',
            $css,
        );
    }

    public function testWithoutAFaceThemeIsTheSystemStack(): void
    {
        $vocabulary = $this->vocabulary(null);
        self::assertNull($vocabulary->face());
        self::assertSame(FontStacks::SYSTEM, $vocabulary->themeFaceStack());
        self::assertStringContainsString(
            '.t-font-theme { font-family: ' . FontStacks::SYSTEM . '; font-synthesis: weight style; }',
            StyleCompiler::compile($vocabulary),
        );
    }

    public function testInheritAndResetAreExact(): void
    {
        $css = StyleCompiler::compile($this->vocabulary());
        self::assertStringContainsString('.t-font-inherit { font-family: inherit; font-synthesis: inherit; }', $css);
        self::assertStringContainsString(
            '.t-font-reset { font-family: revert-layer; font-synthesis: revert-layer; }',
            $css,
        );
        self::assertStringNotContainsString('.md\\:t-font-', $css, 'not responsive');
    }

    public function testTheFaceIsPartOfTheArtifactsName(): void
    {
        $other = ['family' => 'Other', 'stack' => '"Other", serif', 'files' => []];
        self::assertNotSame(
            StyleCompiler::hash($this->vocabulary()),
            StyleCompiler::hash($this->vocabulary($other)),
        );
    }

    public function testAnUploadedFamilysUtilityIsItsId(): void
    {
        self::assertSame('t-font-Ab3dE5fG7hJ9', ClassNames::forFont('Ab3dE5fG7hJ9'));
        self::assertSame('t-font-serif', ClassNames::forFont('serif'));
        self::assertSame('t-font-serif', ClassNames::for('typography.family', 'serif'));
    }

    public function testTheDefaultThemeDeclaresFigtreeAndItsFiles(): void
    {
        self::assertSame([
            'family' => 'Figtree',
            'stack' => self::FIGTREE,
            'files' => [
                ['src' => 'fonts/figtree-roman-latin.woff2', 'weight' => '300 900', 'style' => 'normal'],
                ['src' => 'fonts/figtree-italic-latin.woff2', 'weight' => '300 900', 'style' => 'italic'],
            ],
        ], $this->vocabulary()->face());
    }

    /** Optional metadata: anything unusable is ignored, never an error. */
    public function testAnUnusableFaceIsIgnored(): void
    {
        $unusable = [
            'not an object',
            ['family' => 'X'],
            ['family' => 'X', 'stack' => 'x; color: red'],
            ['family' => 'X', 'stack' => '"X"} body {'],
            ['family' => 'X', 'stack' => '"X\\", serif'],
            ['family' => 'X<', 'stack' => '"X", serif'],
        ];
        foreach ($unusable as $face) {
            $json = json_decode((string) file_get_contents(self::DEFAULT_THEME . '/theme.json'), true);
            $json['face'] = $face;
            $vocabulary = ThemeVocabulary::fromThemeJson($json, self::DEFAULT_THEME);
            self::assertNull($vocabulary->face(), json_encode($face) ?: '');
            self::assertSame(FontStacks::SYSTEM, $vocabulary->themeFaceStack());
        }
        // A file entry that is not usable is dropped; the face stays.
        $vocabulary = $this->vocabulary(['family' => 'X', 'stack' => '"X", serif', 'files' => [
            ['src' => '../escape.woff2', 'weight' => '400', 'style' => 'normal'],
            ['src' => 'fonts/x.woff2', 'weight' => 'heavy', 'style' => 'normal'],
            ['src' => 'fonts/x.woff2', 'weight' => '400', 'style' => 'oblique'],
            ['src' => 'fonts/x.woff2', 'weight' => '400', 'style' => 'normal'],
        ]]);
        $kept = [['src' => 'fonts/x.woff2', 'weight' => '400', 'style' => 'normal']];
        self::assertSame($kept, $vocabulary->face()['files'] ?? null);
    }
}
