<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The mini-cart may render more than once on a page — in the header and again in a layout, or in every
 * card of an Entry list — and each toggle controls its own panel: every panel has its own id, and each
 * toggle's aria-controls names the panel beside it (review of 29cabb70, a fixed id before).
 */
final class MiniCartMarkupTest extends AppTestCase
{
    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks, array $context = []): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $extension->setLocale('en');
        return (string) $extension->blocks($env, $context + ['entry' => null, 'site' => []], $blocks);
    }

    private static function assertEachToggleControlsItsOwnPanel(string $html, int $count): void
    {
        preg_match_all('~aria-controls="([^"]+)"~', $html, $controls);
        preg_match_all('~<div id="([^"]+)" class="thallo-block-mini-cart__panel"~', $html, $panels);
        self::assertCount($count, $panels[1]);
        self::assertCount($count, array_unique($panels[1]), 'every panel its own id');
        self::assertSame($panels[1], $controls[1], 'each toggle names its own panel');
    }

    public function testTwoMiniCartsOnAPageEachControlTheirOwnPanel(): void
    {
        $html = $this->render([
            ['id' => 'minicarta001', 'type' => 'mini-cart', 'data' => []],
            ['id' => 'minicartb001', 'type' => 'mini-cart', 'data' => []],
        ]);
        self::assertEachToggleControlsItsOwnPanel($html, 2);
    }

    public function testAMiniCartInEveryCardControlsItsOwnPanel(): void
    {
        $items = array_fill(0, 3, ['uuid' => 'itementry001', 'fields' => ['title' => 'Item']]);
        $html = $this->render([[
            'id' => 'minicartloop', 'type' => 'entry_loop', 'data' => ['card' => [
                ['id' => 'minicardcart', 'type' => 'mini-cart', 'data' => []],
            ]], 'settings' => [],
        ]], ['layout_context' => ['items' => $items]]);
        self::assertEachToggleControlsItsOwnPanel($html, 3);
    }
}
