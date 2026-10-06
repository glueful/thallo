<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Letter spacing, text transform and text decoration (settings version 12) through the real block
 * templates: each lands on the target the typography group maps to — or a part's own element — as
 * one utility for every width; an explicit `none` is its own utility, a reset is `-reset`; a block's
 * own value beats its style class's; and casing and letter spacing never move each other.
 */
final class TextStyleSettingsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks): string
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
        return $env->createTemplate('{{ blocks(l) }}')->render(['l' => $blocks]);
    }

    /** @return array{type: string, value: string} */
    private static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
    }

    /** @param array<string,mixed> $typography */
    private function headingClasses(array $typography, array $classes = []): string
    {
        $html = $this->render([['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['typography' => $typography], 'classes' => $classes]]]);
        preg_match('/<h\d class="([^"]*thallo-block-heading[^"]*)"/', $html, $m);
        self::assertNotEmpty($m, $html);
        return $m[1];
    }

    /** @return list<string> the classes of one stem in a class attribute */
    private static function stem(string $classAttr, string $stem): array
    {
        preg_match_all('/(?<![\w:-])t-' . $stem . '-[a-z-]+/', $classAttr, $m);
        return $m[0];
    }

    public function testAHeadingTakesEachAsOneUtility(): void
    {
        $classes = $this->headingClasses([
            'letter_spacing' => self::choice('wide'),
            'transform' => self::choice('uppercase'),
            'decoration' => self::choice('line-through'),
        ]);
        self::assertSame(['t-tracking-wide'], self::stem($classes, 'tracking'));
        self::assertSame(['t-case-uppercase'], self::stem($classes, 'case'));
        self::assertSame(['t-decor-line-through'], self::stem($classes, 'decor'));
        self::assertStringNotContainsString('md:t-', $classes, 'set once for every width');
    }

    public function testNoneIsAValueAndAResetIsAReset(): void
    {
        $none = $this->headingClasses(['transform' => self::choice('none'), 'decoration' => self::choice('none')]);
        self::assertSame(['t-case-none'], self::stem($none, 'case'));
        self::assertSame(['t-decor-none'], self::stem($none, 'decor'));
        $reset = $this->headingClasses(['transform' => ['type' => 'reset'], 'letter_spacing' => ['type' => 'reset']]);
        self::assertSame(['t-case-reset'], self::stem($reset, 'case'));
        self::assertSame(['t-tracking-reset'], self::stem($reset, 'tracking'));
        $removed = $this->headingClasses([]);
        self::assertSame([], [...self::stem($removed, 'case'), ...self::stem($removed, 'decor')]);
    }

    public function testCasingAndLetterSpacingNeverMoveEachOther(): void
    {
        $set = static fn (string $spacing, string $casing): array => [
            'letter_spacing' => self::choice($spacing),
            'transform' => self::choice($casing),
        ];
        $before = $this->headingClasses($set('wider', 'uppercase'));
        $casing = $this->headingClasses($set('wider', 'lowercase'));
        $spacing = $this->headingClasses($set('tight', 'uppercase'));
        self::assertSame(self::stem($before, 'tracking'), self::stem($casing, 'tracking'), 'casing left spacing alone');
        self::assertSame(self::stem($before, 'case'), self::stem($spacing, 'case'), 'spacing left casing alone');
        $uppercaseOnly = $this->headingClasses(['transform' => self::choice('uppercase')]);
        self::assertSame([], self::stem($uppercaseOnly, 'tracking'), 'uppercase brings no tracking');
    }

    public function testABlocksOwnValueBeatsItsStyleClass(): void
    {
        $this->connection()->table('style_classes')->insert([
            'id' => 'shoutclass01', 'name' => 'Shout', 'name_key' => 'shout', 'version' => 1,
            'style' => json_encode(['typography' => ['transform' => self::choice('uppercase'),
                'letter_spacing' => self::choice('wide')]]),
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(StyleClassProvider::class)->refresh();
        $classes = $this->headingClasses(['transform' => self::choice('none')], ['shoutclass01']);
        self::assertSame(['t-case-none'], self::stem($classes, 'case'), 'the block wins');
        self::assertSame(['t-tracking-wide'], self::stem($classes, 'tracking'), 'the class still gives spacing');
    }

    public function testALinksPartTakesItsOwnDecoration(): void
    {
        $html = $this->render([['id' => 'links0000001', 'type' => 'links',
            'data' => ['title' => 'Explore', 'items' => [
                ['label' => 'One', 'url' => '/one'],
                ['label' => 'Two', 'url' => '/two'],
            ]],
            'settings' => [
                'style' => ['typography' => ['transform' => self::choice('uppercase')]],
                'parts' => ['link' => ['typography' => ['decoration' => self::choice('underline')]]],
            ]]]);
        preg_match_all('/class="([^"]*thallo-block-links__link[^"]*)"/', $html, $links);
        self::assertCount(2, $links[1]);
        foreach ($links[1] as $link) {
            self::assertSame(['t-decor-underline'], self::stem($link, 'decor'));
            self::assertSame([], self::stem($link, 'case'), "the block's casing stays on its title");
        }
        preg_match('/class="([^"]*thallo-block-links__title[^"]*)"/', $html, $title);
        self::assertSame(['t-case-uppercase'], self::stem($title[1], 'case'));
    }
}
