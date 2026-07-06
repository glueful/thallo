<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;

/**
 * The vendoring-time security review as a regression gate (icon-library spec):
 * every shipped SVG must stay free of active content, and the brand set must
 * keep its currentColor normalization. Guards future upstream refreshes.
 */
final class IconAssetsTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3) . '/packages/thallo-render/resources/icons';
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $out = glob($this->root() . '/' . $dir . '/*.svg');
        self::assertNotFalse($out);
        self::assertNotEmpty($out, "no vendored SVGs under $dir/");
        return $out;
    }

    public function testEveryVendoredSvgIsFreeOfActiveContent(): void
    {
        foreach (array_merge($this->files('lucide'), $this->files('brands')) as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringNotContainsString('<script', $svg, $name);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $svg, $name);
            self::assertStringNotContainsString('href="http', $svg, $name);
            self::assertStringNotContainsString('<foreignObject', $svg, $name);
        }
    }

    public function testBrandSvgsAreNormalizedToCurrentColor(): void
    {
        foreach ($this->files('brands') as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringContainsString('fill="currentColor"', $svg, $name);
            // No OTHER fixed paint values anywhere in the file.
            preg_match_all('/(?:fill|stroke)="([^"]*)"/', $svg, $m);
            foreach ($m[1] as $paint) {
                self::assertContains($paint, ['currentColor', 'none'], "$name carries fixed paint '$paint'");
            }
        }
    }

    public function testLucideSvgsRemainCurrentColorCompatible(): void
    {
        foreach ($this->files('lucide') as $file) {
            $svg = (string) file_get_contents($file);
            $name = basename($file);
            preg_match_all('/(?:fill|stroke)="([^"]*)"/', $svg, $m);
            foreach ($m[1] as $paint) {
                self::assertContains($paint, ['currentColor', 'none'], "$name carries fixed paint '$paint'");
            }
        }
    }

    public function testVendoredManifestRecordsVersionsAndNormalizationRule(): void
    {
        $md = (string) file_get_contents($this->root() . '/VENDORED.md');
        self::assertStringContainsString('lucide', $md);
        self::assertStringContainsString('simple-icons', $md);
        self::assertMatchesRegularExpression('/\bv?\d+\.\d+/', $md, 'no pinned upstream version recorded');
        self::assertStringContainsString('fill="currentColor"', $md, 'normalization rule not documented');
    }

    /**
     * Strict curation (plan review pin): the shipped brand set must equal the
     * manifest's curated list EXACTLY — a missing file means a slug typo or a
     * silent drop at vendoring; an extra file means an undocumented addition.
     */
    public function testShippedBrandSetMatchesTheManifestExactly(): void
    {
        $md = (string) file_get_contents($this->root() . '/VENDORED.md');
        // The "## Curated brands" section lists one slug per line until the next prose/heading.
        $found = preg_match('/## Curated brands\n(.*?)\n\n[^a-z]/s', $md, $m);
        self::assertSame(1, $found, 'curated list section missing');
        preg_match_all('/^[a-z0-9-]+$/m', trim($m[1]), $slugs);
        $manifest = $slugs[0];
        sort($manifest);
        self::assertNotEmpty($manifest, 'curated list is empty');

        $shipped = array_map(
            static fn(string $f): string => basename($f, '.svg'),
            $this->files('brands'),
        );
        sort($shipped);
        self::assertSame($manifest, $shipped);
    }
}
