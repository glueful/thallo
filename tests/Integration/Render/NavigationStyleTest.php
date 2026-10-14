<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Helpers\Utils;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Navigation\MenuRepository;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * The Navigation block's Style tab: Typography on the whole menu, the gap between its items, and
 * sections for every menu item (with its hover look), the current page's item — the pill — the
 * submenu panel and the submenu's items. The current page's section wins over the item's, at rest
 * and under the pointer. The menu button — the phone's hamburger — is a section of its own: its icon's
 * colour and size, its background, border and corners (settings version 21).
 */
final class NavigationStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $menus = $this->container()->get(MenuRepository::class);
        $menu = $menus->createMenu('styled', 'Styled');
        $row = static fn (string $uuid, ?string $parent, int $position, string $url, string $label): array => [
            'uuid' => $uuid, 'parent_uuid' => $parent, 'position' => $position, 'kind' => 'url',
            'entry_uuid' => null, 'url' => $url, 'labels' => json_encode(['en' => $label]),
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $shop = Utils::generateNanoID();
        $menus->replaceTree((string) $menu['uuid'], 0, [
            $row(Utils::generateNanoID(), null, 0, '/about', 'About'),
            $row(Utils::generateNanoID(), null, 1, '/contact', 'Contact'),
            $row($shop, null, 2, '', 'Shop'),
            $row(Utils::generateNanoID(), $shop, 0, '/shop/men', 'Men'),
            $row(Utils::generateNanoID(), $shop, 1, '/shop/women', 'Women'),
        ]);
    }

    /** @param array<string,mixed> $settings */
    private function render(array $settings, string $currentPath = '/about'): string
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $block = ['id' => 'navstyled001', 'type' => 'navigation', 'data' => ['menu' => 'styled'],
            'settings' => $settings];
        return $this->container()->get(TwigFactory::class)->environment()
            ->createTemplate('{{ blocks(items) }}')
            ->render(['items' => [$block], 'current_path' => $currentPath]);
    }

    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    /** The class attribute of the element whose first class is `$class` and that holds `$text`. */
    private static function classesOf(string $html, string $class, string $text): string
    {
        $found = preg_match(
            '~<(?:a|summary)\b[^>]*class="(' . preg_quote($class, '~') . '(?: [^"]*)?)"[^>]*>'
                . '(?:(?!</?(?:a|summary)\b).)*' . preg_quote($text, '~') . '~s',
            $html,
            $m,
        );
        self::assertSame(1, $found, "no {$class} holding {$text}");
        return $m[1];
    }

    public function testNothingSetChangesNothing(): void
    {
        $html = $this->render([]);
        self::assertStringContainsString('<ul class="thallo-block-navigation__list">', $html);
        self::assertStringContainsString(
            '<a class="thallo-block-navigation__link" href="/about" aria-current="page">',
            $html,
        );
        self::assertStringContainsString('<ul class="thallo-block-navigation__submenu" data-nav-panel>', $html);
    }

    public function testTypographyStylesTheWholeMenu(): void
    {
        $bold = ['base' => ['type' => 'choice', 'value' => 'bold']];
        $html = $this->render(['style' => ['typography' => ['weight' => $bold]]]);
        self::assertMatchesRegularExpression(
            '~<div class="thallo-block thallo-block-navigation [^"]* t-weight-bold"~',
            $html,
        );
    }

    public function testTheGapSpacesTheMenusItems(): void
    {
        $html = $this->render(['style' => ['layout' => ['gap' => [
            'column' => ['base' => self::token('spacing.lg')],
        ]]]]);
        self::assertMatchesRegularExpression('~<ul class="thallo-block-navigation__list t-gapx-lg[^"]*">~', $html);
    }

    public function testTheMenuItemSectionStylesEveryTopLevelItemWithItsHoverLook(): void
    {
        $html = $this->render(['parts' => ['item' => [
            'colors' => ['text' => self::token('color.muted')],
            'hover' => ['colors' => ['surface' => self::token('color.accent')]],
        ]]], '/elsewhere');
        foreach (['About', 'Contact', 'Shop'] as $label) {
            $classes = self::classesOf($html, 'thallo-block-navigation__link', $label);
            self::assertStringContainsString('t-fg-muted', $classes, $label);
            self::assertStringContainsString('t-hover-bg-accent', $classes, $label);
        }
        $men = self::classesOf($html, 'thallo-block-navigation__sublink', 'Men');
        self::assertStringNotContainsString('t-fg-muted', $men);
    }

    public function testTheCurrentPageSectionIsThePillAndWinsOverTheItemsAtRestAndOnHover(): void
    {
        $html = $this->render(['parts' => [
            'item' => [
                'colors' => ['surface' => self::token('color.surface'), 'text' => self::token('color.muted')],
                'radius' => self::token('radius.sm'),
                'hover' => ['colors' => ['surface' => self::token('color.muted')]],
            ],
            'current' => [
                'colors' => ['surface' => self::token('color.accent')],
                'radius' => self::token('radius.full'),
            ],
        ]]);
        $current = self::classesOf($html, 'thallo-block-navigation__link', 'About');
        self::assertStringContainsString('t-bg-accent', $current);
        self::assertStringContainsString('t-radius-full', $current);
        // What the current page sets, it decides — under the pointer too; the rest is the item's.
        self::assertStringNotContainsString('t-bg-surface', $current);
        self::assertStringNotContainsString('t-radius-sm', $current);
        self::assertStringNotContainsString('t-hover-bg-muted', $current);
        self::assertStringContainsString('t-fg-muted', $current);
        $other = self::classesOf($html, 'thallo-block-navigation__link', 'Contact');
        self::assertStringContainsString('t-bg-surface', $other);
        self::assertStringContainsString('t-hover-bg-muted', $other);
        self::assertStringNotContainsString('t-bg-accent', $other);
    }

    public function testTheSubmenuSectionsStyleThePanelAndItsItems(): void
    {
        $html = $this->render(['parts' => [
            'submenu' => [
                'colors' => ['surface' => self::token('color.surface')],
                'shadow' => ['base' => self::token('shadow.lg')],
            ],
            'submenu_item' => [
                'colors' => ['text' => self::token('color.muted')],
                'hover' => ['colors' => ['text' => self::token('color.accent')]],
            ],
            'current' => ['colors' => ['text' => self::token('color.white')]],
        ]], '/shop/women');
        self::assertSame(1, preg_match('~<ul class="(thallo-block-navigation__submenu[^"]*)"~', $html, $m));
        $panel = $m[1];
        self::assertStringContainsString('t-bg-surface', $panel);
        self::assertStringContainsString('t-shadow-lg', $panel);
        $men = self::classesOf($html, 'thallo-block-navigation__sublink', 'Men');
        self::assertStringContainsString('t-fg-muted', $men);
        self::assertStringContainsString('t-hover-fg-accent', $men);
        // The current page in the submenu takes the Current page section.
        $women = self::classesOf($html, 'thallo-block-navigation__sublink', 'Women');
        self::assertStringContainsString('t-fg-white', $women);
        self::assertStringNotContainsString('t-fg-muted', $women);
        self::assertStringNotContainsString('t-hover-fg-accent', $women);
    }

    public function testTheMenuButtonSectionStylesTheHamburger(): void
    {
        $html = $this->render(['parts' => ['menu_button' => [
            'colors' => ['text' => self::token('color.accent'), 'surface' => self::token('color.surface')],
            'border' => ['width' => ['type' => 'choice', 'value' => 'none']],
            'radius' => self::token('radius.full'),
            'menu_button' => ['size' => ['type' => 'choice', 'value' => 'lg']],
        ]]]);
        self::assertSame(1, preg_match('~<summary class="(thallo-block-navigation__hamburger[^"]*)"~', $html, $m));
        foreach (['t-fg-accent', 't-bg-surface', 't-bw-none', 't-radius-full', 't-mbsize-lg'] as $class) {
            self::assertStringContainsString(' ' . $class, $m[1]);
        }
        // Nothing of it reaches the links.
        $about = self::classesOf($html, 'thallo-block-navigation__link', 'About');
        self::assertStringNotContainsString('t-mbsize-lg', $about);
    }

    /** The room inside the hamburger, per side and per screen size, on the button alone. */
    public function testTheMenuButtonSectionPadsTheHamburger(): void
    {
        $html = $this->render(['parts' => ['menu_button' => [
            'spacing' => ['padding' => [
                'top' => ['base' => self::token('spacing.sm')],
                'left' => ['base' => self::token('spacing.lg'), 'md' => self::token('spacing.md')],
            ]],
        ]]]);
        self::assertSame(1, preg_match('~<summary class="(thallo-block-navigation__hamburger[^"]*)"~', $html, $m));
        foreach (['t-pt-sm', 't-pl-lg', 'md:t-pl-md'] as $class) {
            self::assertStringContainsString(' ' . $class, $m[1]);
        }
        $about = self::classesOf($html, 'thallo-block-navigation__link', 'About');
        self::assertStringNotContainsString('t-pl-lg', $about);
    }

    public function testAnUnstyledMenuButtonIsTheThemes(): void
    {
        self::assertStringContainsString('<summary class="thallo-block-navigation__hamburger">', $this->render([]));
    }
}
