<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A links block's title — a footer column's heading — takes its own text style: size, weight,
 * line height, colour and alignment land on the title, while the block's spacing stays on the
 * block. The links under it are not the title's to restyle.
 */
final class LinksTitleStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $style */
    private function links(array $style, string $title = 'Product'): string
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
            'id' => 'links0000001', 'type' => 'links',
            'data' => ['title' => $title, 'items' => [['label' => 'Design view', 'url' => '/docs']]],
            'settings' => ['style' => $style],
        ]]]);
    }

    private function tag(string $html, string $class): string
    {
        $pattern = '~<[a-z0-9]+[^>]*\bclass="[^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*"[^>]*>~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class . ' in ' . $html);
        return $m[0];
    }

    public function testTheTitlesTextStyleLandsOnTheTitleAndTheSpacingOnTheBlock(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $html = $this->links([
            'typography' => [
                'size' => ['base' => $token('typography.size.sm')],
                'weight' => ['base' => $choice('bold')],
            ],
            'colors' => ['text' => $token('color.muted')],
            'alignment' => ['text' => ['base' => $choice('center')]],
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ]);
        $title = $this->tag($html, 'thallo-block-links__title');
        $root = $this->tag($html, 'thallo-block-links');

        $own = [
            ClassNames::for('typography.size', 'typography.size.sm'),
            ClassNames::for('typography.weight', 'bold'),
            ClassNames::for('colors.text', 'color.muted'),
            ClassNames::for('alignment.text', 'center'),
        ];
        foreach ($own as $class) {
            self::assertStringContainsString(' ' . $class, $title);
            self::assertStringNotContainsString($class, $root);
        }
        $padding = ClassNames::for('spacing.padding.top', 'spacing.lg');
        self::assertStringContainsString(' ' . $padding, $root);
        self::assertStringNotContainsString($padding, $title);
    }

    public function testALinksBlockWithNoTitleRendersNoneAndIsNotAnError(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $html = $this->links(['colors' => ['text' => $token('color.muted')]], '');
        self::assertStringNotContainsString('thallo-block-links__title', $html);
        self::assertStringContainsString('thallo-block-links__list', $html);
    }
}
