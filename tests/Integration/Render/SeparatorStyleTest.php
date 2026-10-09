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
 * Its length — the whole width, or a short or medium accent — and where a shorter line sits are its
 * own settings.
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

    public function testTheLengthAndAlignmentAreTheBlocksOwnModifiers(): void
    {
        $short = $this->separator([], ['length' => 'short', 'align' => 'start']);
        [$root] = self::classesOf($short, 'thallo-block-separator');
        self::assertStringContainsString('thallo-block-separator--length-short', $root);
        self::assertStringContainsString('thallo-block-separator--align-start', $root);
        $html = $this->separator([], ['length' => 'medium', 'align' => 'end']);
        [$medium] = self::classesOf($html, 'thallo-block-separator');
        self::assertStringContainsString('thallo-block-separator--length-medium', $medium);
        self::assertStringContainsString('thallo-block-separator--align-end', $medium);
    }

    public function testUnsetOrUnknownValuesAreTheFullCentredLine(): void
    {
        foreach ([[], ['length' => 'huge', 'align' => 'left']] as $data) {
            [$root] = self::classesOf($this->separator([], $data), 'thallo-block-separator');
            self::assertStringContainsString('thallo-block-separator--length-full', $root);
            self::assertStringContainsString('thallo-block-separator--align-center', $root);
        }
    }

    public function testTheStylesheetDrawsAShortOrMediumLineWhereItIsAligned(): void
    {
        $css = (string) file_get_contents(
            $this->container()->get(ApplicationContext::class)->getBasePath()
                . '/packages/thallo-render/themes/default/assets/blocks.css',
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-separator--length-short \.thallo-block-separator__line \{ flex: 0 0 3\.5rem; \}~',
            $css,
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-separator--length-medium \.thallo-block-separator__line \{ flex: 0 0 8rem; \}~',
            $css,
        );
        foreach (['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'] as $align => $justify) {
            self::assertMatchesRegularExpression(
                '~\.thallo-block-separator--align-' . $align . ' \{ justify-content: ' . $justify . '; \}~',
                $css,
            );
        }
    }
}
