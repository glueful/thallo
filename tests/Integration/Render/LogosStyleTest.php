<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A Logos block's logo size and the gaps between its logos: the size lands on every logo — the
 * scrolling run's copies too, or the loop would jump — per width; the gaps land on the row of
 * logos, which is what spaces them; the block's own spacing stays on the block.
 */
final class LogosStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const LOGOS = ['logoblob0001', 'logoblob0002'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        foreach (self::LOGOS as $uuid) {
            $this->connection()->table('blobs')->insert([
                'uuid' => $uuid, 'name' => $uuid . '.png', 'mime_type' => 'image/png', 'size' => 1,
                'url' => 'uploads/' . $uuid . '.png', 'visibility' => 'public', 'status' => 'active',
                'created_by' => 'user00000001', 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @param array<string,mixed> $style */
    private function logos(array $style, bool $scroll = false): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'logos0000001', 'type' => 'logos',
            'data' => ['title' => 'Trusted by', 'images' => self::LOGOS, 'scroll' => $scroll],
            'settings' => ['style' => $style],
        ]]]);
    }

    /** @return list<string> the class attribute of every element carrying `$class` */
    private static function classesOf(string $html, string $class): array
    {
        preg_match_all('~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~', $html, $m);
        return $m[1];
    }

    /** @return array<string,mixed> */
    private static function sized(): array
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        return ['logos' => [
            'height' => ['base' => $choice('sm'), 'md' => $choice('lg')],
            'max_width' => ['base' => $choice('md')],
        ]];
    }

    public function testTheLogoSizeLandsOnEveryLogoPerWidth(): void
    {
        $html = $this->logos(self::sized());
        $images = self::classesOf($html, 'thallo-block-logos__image');
        self::assertCount(2, $images, $html);
        foreach ($images as $image) {
            self::assertStringContainsString(ClassNames::for('logos.height', 'sm'), $image);
            self::assertStringContainsString(ClassNames::for('logos.height', 'lg', 'md'), $image);
            self::assertStringContainsString(ClassNames::for('logos.max_width', 'md'), $image);
        }
        [$root] = self::classesOf($html, 'thallo-block-logos');
        self::assertStringNotContainsString('t-logoh-', $root);
    }

    public function testAScrollingRunsCopiesTakeTheSizeToo(): void
    {
        $images = self::classesOf($this->logos(self::sized(), true), 'thallo-block-logos__image');
        self::assertCount(4, $images, 'the run and its copy');
        foreach ($images as $image) {
            self::assertStringContainsString(ClassNames::for('logos.height', 'sm'), $image);
            self::assertStringContainsString(ClassNames::for('logos.max_width', 'md'), $image);
        }
    }

    public function testTheGapsLandOnTheRowOfLogosAndTheSpacingOnTheBlock(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $html = $this->logos([
            'layout' => ['gap' => [
                'column' => ['base' => $token('spacing.xl')],
                'row' => ['base' => $token('spacing.sm')],
            ]],
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ]);
        [$track] = self::classesOf($html, 'thallo-block-logos__track');
        [$root] = self::classesOf($html, 'thallo-block-logos');
        $gaps = [ClassNames::for('layout.gap.column', 'spacing.xl'), ClassNames::for('layout.gap.row', 'spacing.sm')];
        foreach ($gaps as $gap) {
            self::assertStringContainsString($gap, $track);
            self::assertStringNotContainsString($gap, $root);
        }
        self::assertStringContainsString(ClassNames::for('spacing.padding.top', 'spacing.lg'), $root);
    }

    public function testTheLogosBlockDeclaresItsSizeAndGaps(): void
    {
        $capabilities = $this->container()->get(BlockStyleRegistry::class)->capabilitiesFor('logos');
        foreach (['logos.height', 'logos.max_width', 'layout.gap.column', 'layout.gap.row'] as $path) {
            self::assertTrue($capabilities->allows($path), $path);
        }
    }
}
