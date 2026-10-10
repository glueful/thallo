<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\ColorsArtifact;
use Thallo\Render\Style\ColorsArtifacts;
use Thallo\Render\Style\StyleCompiler;
use Thallo\Render\Style\ThemeVocabulary;

/**
 * The workspace's colours stylesheet (custom palette spec §3.4): the compiler's colour utilities for
 * each configured brand id, from the same property table, in @layer settings; its hash depends only
 * on which ids are configured.
 */
final class ColorsArtifactTest extends TestCase
{
    public function testItCarriesEveryColourUtilityTheCompilerWritesForAColourName(): void
    {
        // The drift guard: what the compiler writes for `accent`, renamed, is what the stylesheet
        // writes for a brand id — every resting and hover rule, nothing missing.
        $theme = dirname(__DIR__, 3) . '/packages/thallo-render/themes/default';
        $vocabulary = ThemeVocabulary::fromThemeJson(
            json_decode((string) file_get_contents($theme . '/theme.json'), true),
            $theme,
        );
        $compiled = StyleCompiler::compile($vocabulary);
        $accent = array_filter(
            explode("\n", StyleCompiler::colorUtilities(['accent'])),
            static fn (string $line): bool => str_contains($line, 'color-accent') && !str_contains($line, 'contrast'),
        );
        self::assertNotEmpty($accent);
        foreach ($accent as $line) {
            self::assertStringContainsString($line, $compiled, 'compile() writes this accent rule too');
        }
        $brand = StyleCompiler::colorUtilities(['brand-12']);
        self::assertSame(
            count($accent),
            count(array_filter(
                explode("\n", $brand),
                static fn (string $l): bool => str_contains($l, 'color-brand-12'),
            )),
        );
    }

    public function testItDefinesEachIdsVariablesAndUtilitiesInTheSettingsLayer(): void
    {
        $css = ColorsArtifact::compile([4, 12]);
        self::assertStringStartsWith('@layer settings {', $css);
        self::assertStringContainsString('--t-color-brand-4: var(--brand-4);', $css);
        self::assertStringContainsString('--t-color-brand-12-contrast: var(--brand-12-ink);', $css);
        self::assertStringContainsString(
            ClassNames::selector(ClassNames::for('colors.surface', 'color.brand-12')),
            $css,
        );
        self::assertStringNotContainsString('brand-1-', $css);
    }

    public function testOpacityStillModifiesABrandSurface(): void
    {
        // A modifier must follow the utility it modifies: the opacity rules come after the brand
        // surface rules inside this stylesheet, which loads after the per-theme one.
        $css = ColorsArtifact::compile([4]);
        $surface = strpos($css, 'background: var(--t-color-brand-4)');
        $opacity = strpos($css, 'color-mix(in srgb, var(--t-surface');
        self::assertIsInt($surface);
        self::assertIsInt($opacity);
        self::assertGreaterThan($surface, $opacity);
    }

    public function testTheHashDependsOnlyOnWhichIdsAreConfigured(): void
    {
        self::assertSame(ColorsArtifact::hash([2, 4]), ColorsArtifact::hash([4, 2]));
        self::assertNotSame(ColorsArtifact::hash([2, 4]), ColorsArtifact::hash([2, 4, 5]));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', ColorsArtifact::hash([1]));
        self::assertSame('', ColorsArtifact::compile([]));
    }

    public function testEachWorkspaceGetsItsOwnStylesheetInItsOwnDirectory(): void
    {
        // Under tenancy every workspace has its own colours stylesheet (§3.4); the per-theme
        // artifact (CompiledStyleArtifacts) stays shared and is not involved here.
        $dir = sys_get_temp_dir() . '/thallo-colors-' . bin2hex(random_bytes(4));
        $scope = 'tenantA';
        $artifacts = new ColorsArtifacts($dir, static function () use (&$scope): string {
            return $scope;
        });
        try {
            $a = $artifacts->forIds([1, 4]);
            $scope = 'tenantB';
            $b = $artifacts->forIds([2]);
            self::assertNotSame($a['hash'], $b['hash']);
            self::assertFileExists($dir . '/tenantA/' . ColorsArtifacts::fileName($a['hash']));
            self::assertFileExists($dir . '/tenantB/' . ColorsArtifacts::fileName($b['hash']));
            self::assertNull($artifacts->read($a['hash']), 'a workspace never serves another workspace\'s file');
            self::assertSame(['hash' => ColorsArtifact::hash([]), 'css' => ''], $artifacts->forIds([]));
            self::assertSame($a['hash'], ColorsArtifacts::hashFromFileName(ColorsArtifacts::fileName($a['hash'])));
        } finally {
            array_map('unlink', glob($dir . '/*/*') ?: []);
            array_map('rmdir', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
