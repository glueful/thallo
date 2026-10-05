<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * On the stage, every target and part element names itself (block typeface plan Task 10):
 * `style_classes()` appends `thallo-stage-target--<target>` or `thallo-stage-part--<part>`, so the
 * preview bridge can read what a target renders in. A public render carries none. Real templates.
 */
final class StageTargetMarkersTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks, bool $stage): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope($stage ? 'entry' : 'none');
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        try {
            return $env->createTemplate('{{ blocks(l) }}')->render(['l' => $blocks]);
        } finally {
            $extension->setAnnotationScope('none');
        }
    }

    /** @return array<string,mixed> */
    private static function links(string $id, ?string $title = 'Explore'): array
    {
        return ['id' => $id, 'type' => 'links', 'settings' => [], 'data' => ['title' => $title, 'items' => [
            ['label' => 'One', 'url' => '/one'],
            ['label' => 'Two', 'url' => '/two'],
        ]]];
    }

    /** @return list<string> the class attribute of every element carrying the class `$class` */
    private static function classesOf(string $html, string $class): array
    {
        $token = '(?<![\\w-])' . preg_quote($class, '/') . '(?![\\w-])';
        preg_match_all('/class="([^"]*' . $token . '[^"]*)"/', $html, $m);
        return $m[1];
    }

    public function testATargetAndEachPartElementCarryTheirMarkersOnTheStage(): void
    {
        $html = $this->render([self::links('links0000001')], stage: true);
        [$title] = self::classesOf($html, 'thallo-block-links__title');
        self::assertStringContainsString('thallo-stage-target--title', $title);
        $links = self::classesOf($html, 'thallo-block-links__link');
        self::assertCount(2, $links);
        foreach ($links as $link) {
            self::assertStringContainsString('thallo-stage-part--link', $link);
            self::assertStringNotContainsString('thallo-stage-target--', $link);
        }
        [$root] = self::classesOf($html, 'thallo-block-links');
        self::assertStringContainsString('thallo-stage-target--root', $root);
    }

    public function testAnAbsentOptionalTargetHasNoMarker(): void
    {
        $html = $this->render([self::links('links0000001', null)], stage: true);
        self::assertStringNotContainsString('thallo-stage-target--title', $html);
        self::assertCount(2, self::classesOf($html, 'thallo-stage-part--link'));
    }

    public function testANestedBlockCarriesItsMarkersInsideItsOwnWrapper(): void
    {
        $html = $this->render([[
            'id' => 'container0001', 'type' => 'container', 'settings' => [],
            'data' => ['content' => [self::links('links0000002')]],
        ]], stage: true);
        $inner = strpos($html, 'data-thallo-block="links0000002"');
        self::assertNotFalse($inner);
        $title = strpos($html, 'thallo-stage-target--title');
        self::assertNotFalse($title);
        self::assertGreaterThan($inner, $title, 'the title marker sits inside the nested wrapper');
        self::assertSame(1, substr_count($html, 'thallo-stage-target--title'));
        self::assertCount(1, self::classesOf($html, 'thallo-stage-target--inner'), "the container's own target");
    }

    public function testAPublicRenderCarriesNoMarkers(): void
    {
        $html = $this->render([self::links('links0000001')], stage: false);
        self::assertStringNotContainsString('thallo-stage-', $html);
    }
}
