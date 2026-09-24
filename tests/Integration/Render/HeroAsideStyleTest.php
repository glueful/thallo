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
 * A hero's aside — the blocks in its media column — is styled in the Style tab under Aside: its
 * padding and its fill, as a panel of its own. They are the `aside.*` paths and land on the
 * `media` target; the block's own spacing and surface stay the band's, on the root.
 */
final class HeroAsideStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $style */
    private function hero(array $aside, array $style): string
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
            'id' => 'hero00000001', 'type' => 'hero',
            'data' => ['title' => 'A hero', 'orientation' => 'horizontal', 'aside' => $aside],
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
            'aside' => ['padding' => ['base' => $token('spacing.lg')], 'surface' => $token('color.surface')],
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.xl')]]],
            'colors' => ['surface' => $token('color.surface-2')],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function asideBlocks(): array
    {
        return [['id' => 'code00000001', 'type' => 'code', 'data' => ['code' => '{}', 'language' => 'json']]];
    }

    public function testTheAsidesPaddingAndFillLandOnTheAsideAndTheBandsStayOnTheBand(): void
    {
        $html = $this->hero($this->asideBlocks(), $this->style());
        $aside = $this->tag($html, 'thallo-block-hero__media');
        $root = $this->tag($html, 'thallo-block-hero');

        $own = [ClassNames::for('aside.padding', 'spacing.lg'), ClassNames::for('aside.surface', 'color.surface')];
        $band = [
            ClassNames::for('spacing.padding.top', 'spacing.xl'),
            ClassNames::for('colors.surface', 'color.surface-2'),
        ];
        foreach ($own as $class) {
            self::assertStringContainsString(' ' . $class, $aside);
            self::assertStringNotContainsString($class, $root);
        }
        foreach ($band as $class) {
            self::assertStringContainsString(' ' . $class, $root);
            self::assertStringNotContainsString($class, $aside);
        }
    }

    public function testAHeroWithNoAsideRendersNoneAndIsNotAnError(): void
    {
        // The target is optional: no aside and no image, no media element — the settings wait.
        $html = $this->hero([], $this->style());
        self::assertStringNotContainsString('thallo-block-hero__media', $html);
        self::assertStringNotContainsString(ClassNames::for('aside.padding', 'spacing.lg'), $html);
    }
}
