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
 * A tabs block's strip has corners of its own, set in the Style tab under Tabs: the bar's
 * (`tabs.bar_radius`, on the `bar` target — the list) and the tab's (`tabs.tab_radius`, on the
 * `tab` target — every label, so whichever one is active shows it). The block's `radius` stays
 * the panels area's. Three elements, three slots.
 */
final class TabsStripStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param list<string> $labels
     * @param array<string,mixed> $style
     */
    private function tabs(array $labels, array $style): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        $items = [];
        foreach ($labels as $i => $label) {
            $items[] = ['id' => 'tab00000000' . $i, 'type' => 'tab', 'data' => ['label' => $label, 'content' => []]];
        }
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'tabs00000001', 'type' => 'tabs',
            'data' => ['items' => $items],
            'settings' => ['style' => $style],
        ]]]);
    }

    /** @return list<string> every opening tag carrying the class */
    private function tags(string $html, string $class): array
    {
        $pattern = '~<[a-z0-9]+[^>]*\bclass="[^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*"[^>]*>~';
        preg_match_all($pattern, $html, $m);
        return $m[0];
    }

    /** @return array<string,mixed> */
    private function style(): array
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        return [
            'tabs' => ['bar_radius' => $token('radius.full'), 'tab_radius' => $token('radius.lg')],
            'radius' => $token('radius.sm'),
        ];
    }

    public function testEachCornerLandsOnItsOwnElement(): void
    {
        $html = $this->tabs(['One', 'Two'], $this->style());
        $bar = ClassNames::for('tabs.bar_radius', 'radius.full');
        $tab = ClassNames::for('tabs.tab_radius', 'radius.lg');
        $panels = ClassNames::for('radius', 'radius.sm');

        $list = $this->tags($html, 'thallo-block-tabs__list');
        $labels = $this->tags($html, 'thallo-block-tabs__label');
        $area = $this->tags($html, 'thallo-block-tabs__panels');
        self::assertCount(1, $list);
        self::assertCount(2, $labels);
        self::assertCount(1, $area);

        self::assertStringContainsString(' ' . $bar, $list[0]);
        self::assertStringContainsString(' ' . $panels, $area[0]);
        // Every label, not the first: the active tab is whichever radio is checked, in CSS.
        foreach ($labels as $label) {
            self::assertStringContainsString(' ' . $tab, $label);
        }
        self::assertSame(1, substr_count($html, $bar));
        self::assertSame(2, substr_count($html, $tab));
        self::assertSame(1, substr_count($html, $panels));
    }

    public function testATabsBlockWithNoTabsRendersNoLabelAndIsNotAnError(): void
    {
        // The `tab` target is optional: no items, no label to carry it.
        $html = $this->tabs([], $this->style());
        self::assertSame([], $this->tags($html, 'thallo-block-tabs__label'));
        self::assertStringContainsString(' ' . ClassNames::for('tabs.bar_radius', 'radius.full'), $html);
    }
}
