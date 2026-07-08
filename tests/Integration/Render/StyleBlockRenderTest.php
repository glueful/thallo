<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Style-block spec §4.4: the style wrapper renders a scoped skin + class hook. */
final class StyleBlockRenderTest extends AppTestCase
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

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testSkinAndHookAndChildrenRender(): void
    {
        $out = $this->render([[
            'id' => 's1', 'type' => 'style',
            'data' => [
                'accent' => 'rose', 'neutral' => 'zinc', 'class_hook' => 'promo',
                'content' => [['id' => 'r1', 'type' => 'rich_text', 'data' => ['body' => '<p>hi</p>']]],
            ],
        ]]);
        self::assertStringContainsString(
            'class="thallo-block thallo-block-style thallo-skin-rose-zinc thallo-style-promo"',
            $out,
        );
        self::assertStringContainsString('<style>.thallo-skin-rose-zinc{', $out);
        self::assertSame(1, substr_count($out, '<style>'));         // exactly one skin style
        self::assertStringContainsString('thallo-block-style__inner', $out);
        self::assertStringContainsString('hi', $out);               // child rendered
        // P1: __inner is the first element child (canvas host), the <style> comes AFTER.
        self::assertLessThan(strpos($out, '<style>'), strpos($out, 'thallo-block-style__inner'));
    }

    public function testNoReskinStillWrapsAndAppliesHook(): void
    {
        $out = $this->render([[
            'id' => 's2', 'type' => 'style',
            'data' => ['class_hook' => 'plain', 'content' => []],
        ]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style thallo-style-plain"', $out);
        self::assertStringNotContainsString('<style>', $out);       // nothing to skin
    }

    public function testEmptyDataRendersCleanWrapper(): void
    {
        $out = $this->render([['id' => 's3', 'type' => 'style', 'data' => []]]);
        self::assertStringContainsString('class="thallo-block thallo-block-style"', $out);
        self::assertStringNotContainsString('<style>', $out);
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
}
