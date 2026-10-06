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
 * A Shortcode whose name is not chosen yet renders nothing on the site, but on the editor's stage
 * it renders its empty root: the element the stage marks "Empty shortcode", so the block just added
 * can be seen and selected to choose its name. A named one renders the same in both.
 */
final class ShortcodeStageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $data */
    private function render(array $data, string $scope): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope($scope);
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [
            ['id' => 'shortcode001', 'type' => 'shortcode', 'data' => $data, 'settings' => []],
        ]]);
    }

    public function testANamelessShortcodeRendersItsEmptyRootOnTheStageOnly(): void
    {
        $stage = $this->render([], 'entry');
        $emptyRoot = '<div class="thallo-block thallo-block-shortcode[^"]*"[^>]*>\s*</div>';
        self::assertMatchesRegularExpression('~data-thallo-block="shortcode001"[^>]*>\s*' . $emptyRoot . '~', $stage);
        self::assertStringNotContainsString('data-shortcode=', $stage, 'no name, no shortcode');
        self::assertStringNotContainsString('thallo-block-shortcode', $this->render([], 'none'));
    }

    public function testANamedShortcodeIsUnchanged(): void
    {
        self::assertStringContainsString('data-shortcode="year"', $this->render(['name' => 'year'], 'none'));
        self::assertStringContainsString('data-shortcode="year"', $this->render(['name' => 'year'], 'entry'));
    }
}
