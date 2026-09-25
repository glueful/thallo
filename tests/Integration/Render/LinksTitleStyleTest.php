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
 * block. The links under it have a style of their own: the block's `link` part.
 */
final class LinksTitleStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param array<string,mixed> $style
     * @param array<string,mixed> $parts
     */
    private function links(array $style, string $title = 'Product', array $parts = []): string
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
            'data' => ['title' => $title, 'items' => [
                ['label' => 'Design view', 'url' => '/docs'],
                ['label' => 'Pricing', 'url' => '/pricing'],
            ]],
            'settings' => ['style' => $style] + ($parts === [] ? [] : ['parts' => $parts]),
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

    public function testTheLinksTakeTheirOwnStyleFromTheLinkPart(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $html = $this->links(
            ['typography' => ['size' => ['base' => $token('typography.size.lg')]]],
            'Product',
            ['link' => [
                'typography' => [
                    'size' => ['base' => $token('typography.size.sm')],
                    'weight' => ['base' => $choice('bold')],
                ],
                'colors' => ['text' => $token('color.accent')],
                'spacing' => ['padding' => ['top' => ['base' => $token('spacing.none')]]],
            ]],
        );
        $own = [
            ClassNames::for('typography.size', 'typography.size.sm'),
            ClassNames::for('typography.weight', 'bold'),
            ClassNames::for('colors.text', 'color.accent'),
            ClassNames::for('spacing.padding.top', 'spacing.none'),
        ];
        // Every link carries the part's style; the title and the block carry none of it.
        self::assertSame(2, preg_match_all('~<a class="thallo-block-links__link[^"]*"~', $html, $links));
        foreach ($links[0] as $link) {
            foreach ($own as $class) {
                self::assertStringContainsString(' ' . $class, $link);
            }
            self::assertStringNotContainsString(ClassNames::for('typography.size', 'typography.size.lg'), $link);
        }
        $title = $this->tag($html, 'thallo-block-links__title');
        self::assertStringContainsString(ClassNames::for('typography.size', 'typography.size.lg'), $title);
        foreach ($own as $class) {
            self::assertStringNotContainsString($class, $title);
            self::assertStringNotContainsString($class, $this->tag($html, 'thallo-block-links'));
        }
    }
}
