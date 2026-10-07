<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Hover state spec §3 and §5: the hover and opacity values of Button, Links, File and Social link(s)
 * land on the element the pointer is over; a Social links row's hover reaches each link, whose own
 * value wins leaf by leaf; a reset drops the inherited value and keeps the resting one; a block with
 * no hover values renders exactly as before.
 */
final class HoverStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const BLOB = 'hoverblob001';

    // What the four blocks of testABlockWithoutHoverValuesRendersTheSameClasses rendered before the
    // hover state existed (captured on the tree before hover reached the blocks).
    private const CAPTURED_BUTTON = [
        'thallo-block-button__link thallo-block-button__link--solid thallo-block-button__link--primary'
            . ' thallo-block-button__link--md t-radius-full t-bg-ink',
    ];
    private const CAPTURED_LINKS = ['thallo-block-links__link t-size-lg', 'thallo-block-links__link t-size-lg'];
    private const CAPTURED_SOCIAL = [
        'thallo-block-social_link__link t-bg-black',
        'thallo-block-social_link__link t-bg-black',
    ];
    private const CAPTURED_FILE = ['thallo-block-file__link'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->connection()->table('blobs')->insert([
            'uuid' => self::BLOB, 'name' => 'guide.pdf', 'mime_type' => 'application/pdf', 'size' => 1,
            'url' => 'uploads/guide.pdf', 'visibility' => 'public', 'status' => 'active',
            'created_by' => 'user00000001', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    private static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
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

    /** @return list<string> the class attribute of every element carrying `$class` */
    private static function classesOf(string $html, string $class): array
    {
        preg_match_all('~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~', $html, $m);
        return $m[1];
    }

    /** @param array<string,mixed> $settings */
    private static function button(string $id, array $settings = [], array $data = []): array
    {
        return ['id' => $id, 'type' => 'button', 'data' => $data + ['label' => 'Go', 'url' => '/go'],
            'settings' => $settings];
    }

    /** @param array<string,mixed> $settings */
    private static function links(string $id, array $settings = []): array
    {
        return ['id' => $id, 'type' => 'links', 'data' => ['title' => 'More', 'items' => [
            ['label' => 'One', 'url' => '/one'], ['label' => 'Two', 'url' => '/two'],
        ]], 'settings' => $settings];
    }

    /** @param array<string,mixed> $settings */
    private static function file(string $id, array $settings = []): array
    {
        return ['id' => $id, 'type' => 'file', 'data' => ['file' => self::BLOB, 'label' => 'Guide'],
            'settings' => $settings];
    }

    /**
     * @param array<string,mixed> $row the row's settings
     * @param array<string,mixed> $first the first link's settings
     */
    private static function socials(string $id, array $row = [], array $first = []): array
    {
        $link = static fn (string $lid, string $icon, array $settings): array => [
            'id' => $lid, 'type' => 'social_link', 'data' => ['icon' => $icon, 'url' => 'https://example.com/' . $lid],
            'settings' => $settings,
        ];
        return ['id' => $id, 'type' => 'social_links', 'data' => ['items' => [
            $link($id . 'a', 'brand:instagram', $first),
            $link($id . 'b', 'brand:x', []),
        ]], 'settings' => $row];
    }

    private function hoverClass(): void
    {
        $this->connection()->table('style_classes')->insert([
            'id' => 'hoverclass01', 'name' => 'Hover accent', 'name_key' => 'hover-accent', 'version' => 1,
            'style' => json_encode(['hover' => ['colors' => ['surface' => self::token('color.accent')]]]),
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(StyleClassProvider::class)->refresh();
    }

    public function testAButtonsControlCarriesHoverAndOpacity(): void
    {
        $html = $this->render([self::button('hoverbtn0001', ['style' => [
            'opacity' => self::choice('80'),
            'hover' => [
                'colors' => ['surface' => self::token('color.ink'), 'text' => self::token('color.white')],
                'opacity' => self::choice('100'),
            ],
        ]])]);
        [$control] = self::classesOf($html, 'thallo-block-button__link');
        foreach (['t-opacity-80', 't-hover-bg-ink', 't-hover-fg-white', 't-hover-opacity-100'] as $class) {
            self::assertStringContainsString($class, $control);
        }
        [$row] = self::classesOf($html, 'thallo-block-button');
        self::assertStringNotContainsString('t-hover-', $row);
        self::assertStringNotContainsString('t-opacity-', $row);
    }

    public function testALinksBlocksLinksCarryTheHoverTextColour(): void
    {
        $html = $this->render([self::links('hoverlinks01', ['parts' => ['link' => ['hover' => ['colors' => [
            'text' => self::token('color.accent'),
        ]]]]])]);
        $links = self::classesOf($html, 'thallo-block-links__link');
        self::assertCount(2, $links);
        foreach ($links as $classes) {
            self::assertStringContainsString(ClassNames::for('hover.colors.text', 'color.accent'), $classes);
        }
    }

    public function testAFileLinkCarriesItsLinkSection(): void
    {
        $html = $this->render([self::file('hoverfile001', ['parts' => ['link' => [
            'colors' => ['surface' => self::token('color.surface')],
            'radius' => self::token('radius.full'),
            'hover' => ['colors' => ['surface' => self::token('color.accent')]],
        ]]])]);
        [$link] = self::classesOf($html, 'thallo-block-file__link');
        self::assertStringContainsString(ClassNames::for('colors.surface', 'color.surface'), $link);
        self::assertStringContainsString(ClassNames::for('radius', 'radius.full'), $link);
        self::assertStringContainsString(ClassNames::for('hover.colors.surface', 'color.accent'), $link);
    }

    public function testASocialLinkOwnHoverBeatsTheRowsLeafByLeaf(): void
    {
        $html = $this->render([self::socials(
            'hoversocial1',
            ['parts' => ['icon' => ['hover' => ['colors' => [
                'text' => self::token('color.accent'), 'surface' => self::token('color.black'),
            ]]]]],
            ['parts' => ['icon' => ['hover' => ['colors' => ['text' => self::token('color.white')]]]]],
        )]);
        [$a, $b] = self::classesOf($html, 'thallo-block-social_link__link');
        self::assertStringContainsString('t-hover-fg-white', $a);
        self::assertStringContainsString('t-hover-bg-black', $a, 'the row\'s other hover value still reaches it');
        self::assertStringNotContainsString('t-hover-fg-accent', $a);
        self::assertStringContainsString('t-hover-fg-accent', $b);
        self::assertStringContainsString('t-hover-bg-black', $b);
    }

    public function testAResetHoverOnALinkDropsTheRowsValue(): void
    {
        $html = $this->render([self::socials(
            'hoversocial2',
            ['parts' => ['icon' => ['hover' => ['colors' => ['text' => self::token('color.accent')]]]]],
            ['parts' => ['icon' => ['hover' => ['colors' => ['text' => ['type' => 'reset']]]]]],
        )]);
        [$a, $b] = self::classesOf($html, 'thallo-block-social_link__link');
        self::assertStringContainsString('t-hover-fg-reset', $a);
        self::assertStringNotContainsString('t-hover-fg-accent', $a);
        self::assertStringContainsString('t-hover-fg-accent', $b);
    }

    public function testAResetHoverOverAStyleClassDropsTheClasssValue(): void
    {
        $this->hoverClass();
        $html = $this->render([self::button('hoverbtn0002', [
            'classes' => ['hoverclass01'],
            'style' => [
                'colors' => ['surface' => self::token('color.ink')],
                'hover' => ['colors' => ['surface' => ['type' => 'reset']]],
            ],
        ])]);
        [$control] = self::classesOf($html, 'thallo-block-button__link');
        self::assertStringContainsString('t-bg-ink', $control);
        self::assertStringContainsString('t-hover-bg-reset', $control);
        self::assertStringNotContainsString('t-hover-bg-accent', $control);
    }

    public function testAClassHoverValueOnABlockWithoutHoverEmitsNothing(): void
    {
        $this->hoverClass();
        $html = $this->render([['id' => 'hoverhead001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['classes' => ['hoverclass01']]]]);
        self::assertStringContainsString('Hi', $html);
        self::assertStringNotContainsString('t-hover-', $html);
    }

    /** The four blocks of the unchanged-render check, as a site set them before hover existed. */
    private function unchangedBlocks(): string
    {
        return $this->render([
            self::button('plainbtn0001', ['style' => [
                'radius' => self::token('radius.full'),
                'colors' => ['surface' => self::token('color.ink')],
            ]]),
            self::links('plainlinks01', ['parts' => ['link' => ['typography' => [
                'size' => ['base' => self::token('typography.size.lg')],
            ]]]]),
            self::socials('plainsocial1', ['parts' => ['icon' => ['colors' => [
                'surface' => self::token('color.black'),
            ]]]]),
            self::file('plainfile001'),
        ]);
    }

    public function testABlockWithoutHoverValuesRendersTheSameClasses(): void
    {
        $html = $this->unchangedBlocks();
        $captured = [
            'thallo-block-button__link' => self::CAPTURED_BUTTON,
            'thallo-block-links__link' => self::CAPTURED_LINKS,
            'thallo-block-social_link__link' => self::CAPTURED_SOCIAL,
            // With no part settings, the File link's new style_classes('link') must add nothing.
            'thallo-block-file__link' => self::CAPTURED_FILE,
        ];
        foreach ($captured as $class => $expected) {
            self::assertSame($expected, self::classesOf($html, $class), $class);
        }
        self::assertStringNotContainsString('t-hover-', $html);
        self::assertStringNotContainsString('t-opacity-', $html);
    }
}
