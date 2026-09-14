<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Website plan phase 1b: the hero carries any block beside its copy (`aside`) and its
 * background is a choice (`gradient` today, `none`, `muted`, `inverted`), so a landing page
 * hero with an install snippet beside the headline needs no custom CSS.
 */
final class HeroBlockOptionsTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    private function hero(array $data): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'hero00000001', 'type' => 'hero', 'data' => ['title' => 'Hi'] + $data]],
        ]);
    }

    private function css(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/themes/default/assets/blocks.css'
        );
    }

    public function testAsideBlocksRenderInTheMediaSlotInsteadOfAnImage(): void
    {
        $out = $this->hero([
            'orientation' => 'horizontal',
            'aside' => [[
                'id' => 'code00000001',
                'type' => 'code',
                'data' => ['code' => 'composer install', 'language' => 'bash'],
            ]],
        ]);

        self::assertMatchesRegularExpression(
            '~<div class="thallo-block-hero__media thallo-block-hero__media--blocks">\s*'
            . '<figure class="thallo-block thallo-block-code"~',
            $out,
        );
        self::assertStringNotContainsString('<img', $out);
    }

    public function testTheBackgroundDefaultsToTheGradientAndUnknownValuesDegradeToIt(): void
    {
        self::assertStringContainsString('thallo-block-hero--bg-gradient', $this->hero([]));
        self::assertStringContainsString('thallo-block-hero--bg-gradient', $this->hero(['background' => 'plaid']));
    }

    public function testTheBackgroundCanBeSwitchedOff(): void
    {
        $out = $this->hero(['background' => 'none']);

        self::assertStringContainsString('thallo-block-hero--bg-none', $out);
        self::assertStringNotContainsString('thallo-block-hero--bg-gradient', $out);
    }

    public function testTheThemeStylesEveryBackgroundChoice(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.thallo-block-hero--bg-gradient {', $css);
        self::assertStringContainsString('.thallo-block-hero--bg-none {', $css);
        self::assertStringContainsString('.thallo-block-hero--bg-muted {', $css);
        self::assertStringContainsString('.thallo-block-hero--bg-inverted {', $css);
        self::assertStringContainsString('.thallo-block-hero__media--blocks', $css);
    }
}
