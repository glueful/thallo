<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * A Feature's Style tab: its marker's colour, background and size (beside its corners and shadow);
 * the space between the marker and the text; the title's typography; and the description's own
 * typography, colour and the space above it.
 */
final class FeatureStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $data */
    private function render(array $settings, array $data = []): string
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
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'feature00001', 'type' => 'feature',
            'data' => $data + ['icon' => 'check', 'title' => 'Fast', 'description' => 'It is quick.'],
            'settings' => $settings,
        ]]]);
    }

    /** @return string the class attribute of the first element carrying `$class` */
    private static function classOf(string $html, string $class): string
    {
        $pattern = '~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class);
        return $m[1];
    }

    public function testTheFeatureOffersTheNewSettings(): void
    {
        $caps = $this->container()->get(BlockStyleRegistry::class)->capabilitiesFor('feature');
        foreach (['marker.color', 'marker.background', 'marker.size', 'feature.gap', 'typography.size'] as $path) {
            self::assertTrue($caps->allows($path), $path);
        }
        $targets = $this->container()->get(BlockStyleRegistry::class)->targetsFor('feature');
        self::assertNotNull($targets);
        self::assertSame('marker', $targets->targetFor('marker.color'));
        self::assertSame('root', $targets->targetFor('feature.gap'));
        self::assertSame('title', $targets->targetFor('typography.size'));
        foreach (['typography.size', 'typography.line_height', 'colors.text', 'spacing.margin.top'] as $path) {
            self::assertTrue($targets->partCapabilities('description')->allows($path), $path);
        }
    }

    public function testTheMarkersColourBackgroundAndSizeLandOnTheMarker(): void
    {
        $html = $this->render(['style' => ['marker' => [
            'color' => self::token('color.accent'),
            'background' => self::token('color.surface'),
            'size' => ['base' => self::token('typography.size.lg'), 'md' => self::token('typography.size.xl')],
        ]]]);
        $marker = self::classOf($html, 'thallo-block-feature__marker');
        foreach (['t-mcolor-accent', 't-mbg-surface', 't-msize-lg', 'md:t-msize-xl'] as $class) {
            self::assertStringContainsString($class, $marker);
        }
        self::assertStringNotContainsString('t-mcolor-', self::classOf($html, 'thallo-block-feature'));
    }

    public function testTheGapLandsOnTheBlock(): void
    {
        $html = $this->render(['style' => ['feature' => ['gap' => ['base' => self::token('spacing.lg')]]]]);
        self::assertStringContainsString('t-fgap-lg', self::classOf($html, 'thallo-block-feature'));
    }

    public function testTheTitleAndTheDescriptionEachTakeTheirOwnTypography(): void
    {
        $html = $this->render([
            'style' => ['typography' => ['size' => ['base' => self::token('typography.size.xl')]]],
            'parts' => ['description' => [
                'typography' => ['size' => ['base' => self::token('typography.size.sm')]],
                'colors' => ['text' => self::token('color.text')],
                'spacing' => ['margin' => ['top' => ['base' => self::token('spacing.lg')]]],
            ]],
        ]);
        $title = self::classOf($html, 'thallo-block-feature__title');
        $description = self::classOf($html, 'thallo-block-feature__description');
        self::assertStringContainsString('t-size-xl', $title);
        self::assertStringNotContainsString('t-size-sm', $title);
        foreach (['t-size-sm', 't-fg-text', 't-mt-lg'] as $class) {
            self::assertStringContainsString($class, $description);
        }
        self::assertStringNotContainsString('t-size-xl', $description);
    }

    public function testAFeatureWithoutTheNewSettingsRendersAsBefore(): void
    {
        $html = $this->render([]);
        self::assertSame(
            'thallo-block-feature__description',
            self::classOf($html, 'thallo-block-feature__description'),
        );
        self::assertSame(
            'thallo-block-feature__marker thallo-block-feature__marker--icon thallo-block-feature__icon',
            self::classOf($html, 'thallo-block-feature__marker'),
        );
    }
}
