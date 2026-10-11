<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\BlockTypeStylePaths;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Which corners the Corners size rounds (`corners`, settings version 18) and which sides a border is
 * drawn on (`border.sides`, any set of them): each lands on the element its radius or border does —
 * a Button's control, a Card's card — and every block that offers Corners offers it.
 */
final class CornersStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $block */
    private function render(array $block): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [$block]]);
    }

    private static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
    }

    public function testAButtonsChosenCornersLandOnItsControl(): void
    {
        $html = $this->render(['id' => 'cornerbtn001', 'type' => 'button',
            'data' => ['label' => 'Go', 'url' => '/go'],
            'settings' => ['style' => [
                'radius' => ['type' => 'token', 'value' => 'radius.lg'],
                'corners' => self::choice('tl-bl'),
            ]]]);
        self::assertMatchesRegularExpression(
            '~class="thallo-block-button__link[^"]* t-radius-lg t-corners-tl-bl"~',
            $html,
        );
        self::assertMatchesRegularExpression('~class="thallo-block thallo-block-button"~', $html);
    }

    public function testACardsChosenCornersAndSidesLandOnTheCard(): void
    {
        $html = $this->render(['id' => 'cornercard01', 'type' => 'card',
            'data' => ['title' => 'Hi'],
            'settings' => ['style' => [
                'radius' => ['type' => 'token', 'value' => 'radius.lg'],
                'corners' => self::choice('tr-br'),
                'border' => ['width' => self::choice('thin'), 'sides' => self::choice('top-bottom')],
            ]]]);
        self::assertMatchesRegularExpression('~class="thallo-block thallo-block-card[^"]*\bt-corners-tr-br\b~', $html);
        self::assertMatchesRegularExpression(
            '~class="thallo-block thallo-block-card[^"]*\bt-bsides-top-bottom\b~',
            $html,
        );
    }

    public function testEveryBlockThatOffersCornersOffersWhichCorners(): void
    {
        $offered = 0;
        foreach ($this->container()->get(BlockTypeRepository::class)->all() as $type) {
            $paths = BlockTypeStylePaths::for($type);
            foreach (['block' => $paths['block']] + $paths['parts'] as $owner => $list) {
                self::assertSame(
                    in_array('radius', $list, true),
                    in_array('corners', $list, true),
                    "{$type['slug']} {$owner}",
                );
                $offered += in_array('corners', $list, true) ? 1 : 0;
            }
        }
        self::assertGreaterThan(5, $offered);
    }
}
