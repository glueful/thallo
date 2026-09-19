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
 * A feature's marker — its icon chip or number badge — has corners and a shadow of its own, set
 * in the Style tab under Marker. They are the `marker.*` paths and land on the `marker` target;
 * the block's `radius` and `shadow` stay the card's, on the root. Two elements, two slots.
 */
final class FeatureMarkerStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $style
     */
    private function feature(array $data, array $style): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $extension,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'feat00000001', 'type' => 'feature',
            'data' => ['title' => 'Step', 'description' => 'Text'] + $data,
            'settings' => ['style' => $style],
        ]]]);
    }

    private function tag(string $html, string $class): string
    {
        $pattern = '~<[a-z0-9]+[^>]*\bclass="[^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*"[^>]*>~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class . ' in ' . $html);
        return $m[0];
    }

    /** @return array<string,mixed> */
    private function style(): array
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        return [
            'marker' => ['radius' => $token('radius.full'), 'shadow' => ['base' => $token('shadow.md')]],
            'radius' => $token('radius.lg'),
            'shadow' => ['base' => $token('shadow.sm')],
        ];
    }

    public function testTheMarkersCornersAndShadowLandOnTheMarkerAndTheCardsStayOnTheCard(): void
    {
        $html = $this->feature(['marker' => 'number', 'number' => '1'], $this->style());
        $marker = $this->tag($html, 'thallo-block-feature__marker');
        $root = $this->tag($html, 'thallo-block-feature');

        $own = [ClassNames::for('marker.radius', 'radius.full'), ClassNames::for('marker.shadow', 'shadow.md')];
        $card = [ClassNames::for('radius', 'radius.lg'), ClassNames::for('shadow', 'shadow.sm')];
        foreach ($own as $class) {
            self::assertStringContainsString(' ' . $class, $marker);
            self::assertStringNotContainsString($class, $root);
        }
        foreach ($card as $class) {
            self::assertStringContainsString(' ' . $class, $root);
            self::assertStringNotContainsString($class, $marker);
        }
    }

    public function testAnIconMarkerTakesThemToo(): void
    {
        $html = $this->feature(['marker' => 'icon', 'icon' => 'star'], $this->style());
        $marker = $this->tag($html, 'thallo-block-feature__marker');
        self::assertStringContainsString(' ' . ClassNames::for('marker.radius', 'radius.full'), $marker);
    }

    public function testAFeatureWithNoMarkerRendersNoneAndIsNotAnError(): void
    {
        // The target is optional: `marker = none`, or a number marker with no number, has no element.
        $html = $this->feature(['marker' => 'none'], $this->style());
        self::assertStringNotContainsString('thallo-block-feature__marker', $html);
        self::assertStringNotContainsString(ClassNames::for('marker.radius', 'radius.full'), $html);
    }
}
