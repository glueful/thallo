<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.4: the style wrapper renders a scoped skin + class hook. */
final class StyleBlockRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testSkinAndChildrenRender(): void
    {
        $out = $this->render([[
            'id' => 's1', 'type' => 'style',
            'data' => [
                'accent' => 'rose', 'neutral' => 'zinc',
                'content' => [['id' => 'r1', 'type' => 'rich_text', 'data' => ['body' => '<p>hi</p>']]],
            ],
        ]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style thallo-skin-rose-zinc"', $out);
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertSame(1, substr_count($out, '<style>'));         // exactly one skin style
        self::assertStringContainsString('thallo-block-style__inner', $out);
        self::assertStringContainsString('hi', $out);               // child rendered
        // P1: __inner is the first element child (canvas host), the <style> comes AFTER.
        self::assertLessThan(strpos($out, '<style>'), strpos($out, 'thallo-block-style__inner'));
    }

    public function testEmptyDataRendersCleanWrapper(): void
    {
        $out = $this->render([['id' => 's3', 'type' => 'style', 'data' => []]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style"', $out);
        self::assertStringNotContainsString('<style>', $out);
        self::assertStringNotContainsString(' style="', $out);
    }

    public function testNestedStyleBlocksEachEmitTheirSkin(): void
    {
        $out = $this->render([[
            'id' => 'o', 'type' => 'style',
            'data' => ['accent' => 'rose', 'content' => [[
                'id' => 'i', 'type' => 'style',
                'data' => ['neutral' => 'zinc', 'content' => []],
            ]]],
        ]]);
        self::assertStringContainsString('thallo-skin-rose-none', $out);
        self::assertStringContainsString('thallo-skin-none-zinc', $out);
        self::assertSame(2, substr_count($out, '<style>'));
    }

    public function testShadowSpacingAndCssClassesAreSettingsAndNothingIsInline(): void
    {
        $this->syncBlockStyleDeclarations();
        $out = $this->render([[
            'id' => 'sp1', 'type' => 'style',
            'data' => ['accent' => 'rose', 'content' => []],
            'settings' => [
                'style' => [
                    'shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.lg']],
                    'spacing' => ['padding' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.lg']]]],
                ],
                'advanced' => ['css_classes' => ['promo']],
            ],
        ]]);
        $pattern = '~<div class="thallo-block thallo-block-style thallo-skin-rose-none([^"]*)">~';
        self::assertSame(1, preg_match($pattern, $out, $m), $out);
        foreach ([' t-pt-lg', ' t-shadow-lg', ' promo'] as $class) {
            self::assertStringContainsString($class, $m[1]);
        }
        self::assertStringNotContainsString('thallo-shadow-', $out);
        self::assertStringNotContainsString('--shadow-', $out);
        self::assertStringNotContainsString(' style="', $out);
    }
}
