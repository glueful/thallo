<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Visual builder spec §4.1–4.2: style classes resolve as cascade layers below the instance,
 * in list order, from the request's snapshot; a declaration lands only where the block has the
 * capability and is dormant elsewhere; an archived class still renders.
 */
final class StyleClassRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param array<string,mixed> $settings */
    private function renderHeading(array $settings): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        $list = [['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => $settings]];
        return $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => $list]);
    }

    private function headingTag(string $html): string
    {
        self::assertSame(1, preg_match('~<h[1-6][^>]*>~', $html, $m), $html);
        return $m[0];
    }

    /** @param array<string,mixed> $style */
    private function class(string $name, array $style): string
    {
        return $this->container()->get(StyleClassRepository::class)->create(['name' => $name, 'style' => $style])['id'];
    }

    /** @return array<string,mixed> */
    private static function padding(string $bp, string $token): array
    {
        return ['spacing' => ['padding' => ['top' => [$bp => ['type' => 'token', 'value' => $token]]]]];
    }

    public function testAClassDeclarationLandsOnTheBlockAndIsDormantWithoutTheCapability(): void
    {
        $radius = ['radius' => ['type' => 'token', 'value' => 'radius.lg']];
        $band = $this->class('Band', self::padding('md', 'spacing.lg') + $radius);
        $tag = $this->headingTag($this->renderHeading(['classes' => [$band]]));
        self::assertStringContainsString('md:t-pt-lg', $tag);
        self::assertStringNotContainsString('t-radius', $tag, 'a heading has no radius capability: dormant');
    }

    public function testLaterClassesAndTheInstanceOverrideEarlierLayers(): void
    {
        $a = $this->class('A', self::padding('md', 'spacing.lg'));
        $b = $this->class('B', self::padding('md', 'spacing.xl'));
        $tag = $this->headingTag($this->renderHeading(['classes' => [$a, $b]]));
        self::assertStringContainsString('md:t-pt-xl', $tag);
        self::assertStringNotContainsString('md:t-pt-lg', $tag);

        $instance = ['classes' => [$a, $b], 'style' => self::padding('md', 'spacing.sm')];
        $tag = $this->headingTag($this->renderHeading($instance));
        self::assertStringContainsString('md:t-pt-sm', $tag);
        self::assertStringNotContainsString('md:t-pt-xl', $tag);
    }

    public function testAClassResetEmitsTheResetUtility(): void
    {
        $quiet = $this->class('Quiet', ['spacing' => ['padding' => ['top' => ['md' => ['type' => 'reset']]]]]);
        $tag = $this->headingTag($this->renderHeading(['classes' => [$quiet]]));
        self::assertStringContainsString('md:t-pt-reset', $tag);
    }

    public function testAnArchivedClassStillRenders(): void
    {
        $band = $this->class('Band', self::padding('md', 'spacing.lg'));
        $this->container()->get(StyleClassRepository::class)->archive($band);
        self::assertStringContainsString('md:t-pt-lg', $this->headingTag($this->renderHeading(['classes' => [$band]])));
    }

    public function testTheExtensionReportsTheGenerationItRenderedFrom(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        $before = $extension->styleSnapshotGeneration();
        $this->class('Band', self::padding('md', 'spacing.lg'));
        self::assertSame($before + 1, $extension->styleSnapshotGeneration());
    }
}
