<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The two helpers a layout renders through (type layouts spec §4.1, §5.4, §6.3). `layout_blocks`
 * renders a layout's own blocks — selectable on the layout's stage only; `entry_slot` renders an
 * entry's own blocks — selectable on the entry's stage only, with the nesting depth restarted at
 * the slot so a body valid on its own renders whole inside any layout.
 */
final class LayoutRenderHelpersTest extends AppTestCase
{
    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    protected function tearDown(): void
    {
        $this->extension()->setAnnotationScope('none');
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $content */
    private static function box(string $id, array $content): array
    {
        return [
            'id' => $id, 'type' => 'container',
            'data' => ['element' => 'div', 'content' => $content], 'settings' => [],
        ];
    }

    private static function heading(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => $text, 'level' => 'h2'], 'settings' => []];
    }

    /** @param list<array<string,mixed>> $layout @param list<array<string,mixed>> $body */
    private function render(string $scope, array $layout, array $body, string $tail = ''): string
    {
        $this->extension()->resetPerRenderState();
        $this->extension()->setAnnotationScope($scope);
        return $this->env()->createTemplate('{{ layout_blocks(layout) }}' . $tail)->render([
            'layout' => $layout,
            'entry' => ['uuid' => 'entry0000001', 'fields' => ['body' => $body]],
            'deep' => $body,
        ]);
    }

    private function layout(): array
    {
        return [
            self::heading('layouthead01', 'From the layout'),
            ['id' => 'layoutslot01', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ];
    }

    public function testTheLayoutStageSelectsTheLayoutsBlocksNeverTheBody(): void
    {
        $html = $this->render('layout', $this->layout(), [self::heading('bodyhead0001', 'From the post')]);
        self::assertStringContainsString('data-thallo-block="layouthead01"', $html);
        self::assertStringContainsString('data-thallo-block="layoutslot01"', $html);
        self::assertStringNotContainsString('data-thallo-block="bodyhead0001"', $html);
        self::assertStringNotContainsString('data-thallo-slot="body"', $html);
        // The layout's own list is the stage's root slot: a block dragged in lands in it.
        self::assertStringContainsString('data-thallo-slot="blocks"', $html);
        self::assertStringContainsString('From the post', $html, 'the sample body still shows');
    }

    public function testTheEntryStageSelectsTheBodyNeverTheLayout(): void
    {
        $html = $this->render('entry', $this->layout(), [self::heading('bodyhead0001', 'From the post')]);
        self::assertStringContainsString('data-thallo-block="bodyhead0001"', $html);
        self::assertStringContainsString('data-thallo-slot="body"', $html);
        self::assertStringNotContainsString('data-thallo-block="layouthead01"', $html);
        self::assertStringNotContainsString('data-thallo-block="layoutslot01"', $html);
    }

    public function testOffTheStageNothingIsAnnotated(): void
    {
        $html = $this->render('none', $this->layout(), [self::heading('bodyhead0001', 'From the post')]);
        self::assertStringNotContainsString('data-thallo-block=', $html);
        self::assertStringNotContainsString('data-thallo-slot=', $html);
        self::assertStringContainsString('From the layout', $html);
        self::assertStringContainsString('From the post', $html);
    }

    public function testTheDepthRestartsAtTheSlotAndIsRestoredAfter(): void
    {
        // A body at the maximum depth on its own: four containers, then a heading (depth 5).
        $body = [self::box('b1', [self::box('b2', [self::box('b3', [
            self::box('b4', [self::heading('b5', 'Deepest in the post')]),
        ])])])];
        $slot = ['id' => 's', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []];
        // The slot placed four containers deep in the layout.
        $layout = [self::box('l1', [self::box('l2', [self::box('l3', [
            self::box('l4', [$slot]),
        ])])])];

        $html = $this->render('none', $layout, $body, '<hr>{{ blocks(deep) }}');
        [$inLayout, $after] = explode('<hr>', $html, 2);
        self::assertStringContainsString('Deepest in the post', $inLayout, 'the slot restarts the depth');
        self::assertStringContainsString('Deepest in the post', $after, 'and the depth is restored after it');

        // The same body placed straight into the layout's tree, without the slot: it is cut.
        $inline = [self::box('l1', [self::box('l2', [self::box('l3', [
            self::box('l4', $body),
        ])])])];
        self::assertStringNotContainsString('Deepest in the post', $this->render('none', $inline, []));
    }

    public function testASlotForAFieldThatIsNotAListRendersNothing(): void
    {
        $this->extension()->resetPerRenderState();
        $html = $this->env()->createTemplate('{{ entry_slot("body") }}|{{ entry_slot("missing") }}')->render([
            'entry' => ['fields' => ['body' => 'not a list']],
        ]);
        self::assertSame('|', trim($html));
    }
}
