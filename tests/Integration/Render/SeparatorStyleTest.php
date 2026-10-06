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
 * A separator's border colour is drawn by its lines, so it lands on every line rather than on the
 * block (where `border-color` would colour nothing: the block has no border, and the lines do not
 * inherit it); its label — with its icon — takes the Typography group; spacing stays on the block.
 */
final class SeparatorStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param array<string,mixed> $style
     * @param array<string,mixed> $data
     */
    private function separator(array $style, array $data = ['label' => 'Or']): string
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
            'id' => 'separator001', 'type' => 'separator', 'data' => $data,
            'settings' => ['style' => $style],
        ]]]);
    }

    /** @return list<string> the class attribute of every element carrying `$class` */
    private static function classesOf(string $html, string $class): array
    {
        preg_match_all('~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~', $html, $m);
        return $m[1];
    }

    public function testTheBorderColourLandsOnEveryLineAndNotOnTheBlock(): void
    {
        $colour = ClassNames::for('colors.border', 'color.accent');
        $html = $this->separator(['colors' => ['border' => ['type' => 'token', 'value' => 'color.accent']]]);
        $lines = self::classesOf($html, 'thallo-block-separator__line');
        self::assertCount(2, $lines, $html);
        foreach ($lines as $line) {
            self::assertStringContainsString($colour, $line);
        }
        [$root] = self::classesOf($html, 'thallo-block-separator');
        self::assertStringNotContainsString($colour, $root);

        $plain = $this->separator(['colors' => ['border' => ['type' => 'token', 'value' => 'color.accent']]], []);
        $lines = self::classesOf($plain, 'thallo-block-separator__line');
        self::assertCount(1, $lines, 'an unlabelled separator draws one line');
        self::assertStringContainsString($colour, $lines[0]);
    }

    public function testTheLabelTakesTypographyAndTheBlockKeepsItsSpacing(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $html = $this->separator([
            'typography' => [
                'size' => ['base' => $token('typography.size.lg')],
                'weight' => ['base' => $choice('bold')],
                'transform' => $choice('uppercase'),
            ],
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ], ['label' => 'Or', 'icon' => 'star']);
        [$content] = self::classesOf($html, 'thallo-block-separator__content');
        [$root] = self::classesOf($html, 'thallo-block-separator');
        $own = [
            ClassNames::for('typography.size', 'typography.size.lg'),
            ClassNames::for('typography.weight', 'bold'),
            ClassNames::for('typography.transform', 'uppercase'),
        ];
        foreach ($own as $class) {
            self::assertStringContainsString($class, $content);
            self::assertStringNotContainsString($class, $root);
        }
        self::assertStringContainsString(ClassNames::for('spacing.padding.top', 'spacing.lg'), $root);
    }

    public function testTheSeparatorDeclaresTypographyForItsLabel(): void
    {
        $capabilities = $this->container()->get(BlockStyleRegistry::class)->capabilitiesFor('separator');
        foreach (['typography.size', 'typography.family', 'typography.decoration', 'colors.border'] as $path) {
            self::assertTrue($capabilities->allows($path), $path);
        }
    }
}
