<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Style\ThemeCssLint;
use Thallo\Render\Style\ThemeStylesheetArtifact;
use Thallo\Render\ThemeConfigError;

/** Visual builder spec §2.2–2.4: the layered theme artifact and what a theme sheet may not contain. */
final class ThemeStylesheetArtifactTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/thallo-artifact-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function file(string $name, string $css): string
    {
        file_put_contents($this->dir . '/' . $name, $css);
        return $this->dir . '/' . $name;
    }

    public function testConcatenatesInsideTheThemeLayerInOrderAndHashesByContent(): void
    {
        $a = $this->file('site.css', ':root { --x: 1; }');
        $b = $this->file('blocks.css', '.thallo-block-hero { padding: 1rem; }');
        $c = $this->file('shop.css', '.shop { color: red; }');

        $artifact = ThemeStylesheetArtifact::build([$a, $b], [$c]);

        self::assertStringStartsWith("@layer theme {\n", $artifact->css);
        self::assertStringEndsWith("}\n", $artifact->css);
        self::assertLessThan(strpos($artifact->css, '.thallo-block-hero'), strpos($artifact->css, '--x: 1'));
        self::assertLessThan(
            strpos($artifact->css, '.shop'),
            strpos($artifact->css, '.thallo-block-hero'),
            'contributed last',
        );
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $artifact->hash);

        $again = ThemeStylesheetArtifact::build([$a, $b], [$c]);
        self::assertSame($artifact->hash, $again->hash, 'deterministic');

        file_put_contents($b, '.thallo-block-hero { padding: 2rem; }');
        self::assertNotSame(
            $artifact->hash,
            ThemeStylesheetArtifact::build([$a, $b], [$c])->hash,
            'content changes the hash',
        );
        self::assertSame('theme-' . $artifact->hash . '.css', ThemeStylesheetArtifact::fileName($artifact->hash));
        self::assertSame(
            $artifact->hash,
            ThemeStylesheetArtifact::hashFromFileName('theme-' . $artifact->hash . '.css'),
        );
        self::assertNull(ThemeStylesheetArtifact::hashFromFileName('theme-nope.css'));
    }

    public function testImportCharsetAndNamespaceAreRejectedWithFileAndLine(): void
    {
        $a = $this->file('site.css', ":root { --x: 1; }\n@import url('other.css');");
        try {
            ThemeStylesheetArtifact::build([$a]);
            self::fail('import accepted');
        } catch (ThemeConfigError $e) {
            self::assertStringStartsWith(
                'site.css:2: @import cannot be delivered inside @layer theme',
                $e->getMessage(),
            );
        }
        self::assertCount(1, ThemeCssLint::lint('@charset "utf-8";', 'x.css'));
        self::assertCount(1, ThemeCssLint::lint('@namespace svg url(http://www.w3.org/2000/svg);', 'x.css'));
    }

    public function testImportantOnAManagedPropertyInABlockRuleIsRejectedElsewhereItIsNot(): void
    {
        $bad = ".thallo-block-hero__links .thallo-block-button__link { padding: 1rem !important; }";
        $errors = ThemeCssLint::lint($bad, 'blocks.css');
        self::assertCount(1, $errors);
        self::assertStringContainsString('blocks.css:1: !important on managed property padding', $errors[0]);

        self::assertSame(
            [],
            ThemeCssLint::lint('.site-header { padding: 1rem !important; }', 'site.css'),
            'not a block rule',
        );
        self::assertSame(
            [],
            ThemeCssLint::lint('.thallo-block-hero { z-index: 2 !important; }', 'blocks.css'),
            'not managed',
        );
        self::assertSame(
            [],
            ThemeCssLint::lint("@media (min-width: 52rem) {\n  .thallo-block-hero { padding: 1rem; }\n}", 'blocks.css'),
            'ordinary nested rules are fine',
        );
        self::assertCount(
            1,
            ThemeCssLint::lint(
                "@media (min-width: 52rem) {\n  .thallo-block-hero { color: red !important; }\n}",
                'blocks.css',
            ),
            'nested block rules are still checked',
        );
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(ThemeConfigError::class);
        ThemeStylesheetArtifact::build([$this->dir . '/nope.css']);
    }

    public function testTheShippedThemeBuildsClean(): void
    {
        $theme = dirname(__DIR__, 3) . '/packages/thallo-render/themes/default';
        $files = array_map(static fn (string $f): string => $theme . '/' . $f, [
            'assets/site.css', 'assets/blocks.css', 'assets/navigation.css', 'assets/stepper.css',
        ]);
        $shop = dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css';
        $artifact = ThemeStylesheetArtifact::build($files, [$shop]);
        self::assertStringContainsString('.site-header', $artifact->css);
        self::assertStringContainsString('.thallo-block-hero', $artifact->css);
    }
}
