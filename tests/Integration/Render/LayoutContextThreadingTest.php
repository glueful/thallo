<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * A layout frame's own variables reach its field blocks (type layouts plan C1, P2): block templates
 * render with a fresh context, so the frame hands them `layout_context`, threaded to every depth of
 * the layout's tree — on the public page and on the stage — and taken away again for the entry's
 * body and for the header and footer, which are not the layout's.
 */
final class LayoutContextThreadingTest extends AppTestCase
{
    /** @var list<array<string,mixed>>|null */
    private ?array $headerBefore = null;

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes', null, [$base . '/tests/fixtures/render/context-probe']),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $regions = $this->container()->get(RegionRepository::class);
        $this->headerBefore = $regions->find('header')['blocks'] ?? [];
        $regions->save('header', [self::probe('headerprobe1')], [], null);
    }

    protected function tearDown(): void
    {
        $this->container()->get(RegionRepository::class)->save('header', $this->headerBefore ?? [], [], null);
        $this->extension()->setAnnotationScope('none');
        parent::tearDown();
    }

    private static function probe(string $id): array
    {
        return ['id' => $id, 'type' => 'context_probe', 'data' => [], 'settings' => []];
    }

    /** @param list<array<string,mixed>> $content */
    private static function box(string $id, array $content): array
    {
        return [
            'id' => $id, 'type' => 'container',
            'data' => ['element' => 'div', 'content' => $content], 'settings' => [],
        ];
    }

    public function testTheFramesContextReachesEveryDepthOfTheLayoutAndNothingElse(): void
    {
        $layout = [
            self::probe('rootprobe001'),
            self::box('outerbox0001', [self::box('innerbox0001', [self::probe('deepprobe001')])]),
            ['id' => 'layoutslot01', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ];
        foreach (['none', 'layout'] as $scope) {
            $this->extension()->resetPerRenderState();
            $this->extension()->setAnnotationScope($scope);
            $page = '{{ layout_blocks(layout) }}<header>{{ region_blocks("header") }}</header>';
            $html = $this->env()->createTemplate($page)
                ->render([
                    'layout' => $layout,
                    'layout_context' => ['probe' => 'from-the-frame'],
                    'entry' => ['uuid' => 'entry0000001', 'fields' => ['body' => [self::probe('bodyprobe001')]]],
                ]);
            self::assertSame(2, substr_count($html, '[from-the-frame]'), "{$scope}: the root and deep block get it");
            self::assertSame(2, substr_count($html, '[none]'), "{$scope}: the body and the header do not");
            self::assertMatchesRegularExpression(
                '~<header><p class="thallo-block context-probe">\[none\]</p>\s*</header>~',
                $html,
            );
            if ($scope === 'layout') {
                self::assertStringContainsString('data-thallo-block="deepprobe001"', $html, 'deep, on the stage');
            }
        }
    }
}
