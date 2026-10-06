<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\TwigFactory;

/**
 * The Mini cart's and the Wishlist link's look is their button's: Background, Text colour, Border,
 * Corners and Shadow land on the cart toggle and the wishlist link — the `control` target — and
 * the link's label takes Typography; Spacing, Visibility and Layout stay on the block. And the
 * shop stylesheet those blocks link themselves is served inside `@layer theme`, as the copy in the
 * theme stylesheet is, so a style setting (`@layer settings`) beats its button rules.
 */
final class ShopChromeStyleTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param array<string,mixed> $style @param array<string,mixed> $data */
    private function render(string $type, array $style, array $data = []): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope('none');
        $extension->setLocale('en');
        return (string) $extension->blocks($env, ['entry' => null, 'site' => []], [
            ['id' => 'shopchrome01', 'type' => $type, 'data' => $data, 'settings' => isset($style['parts'])
                ? ['parts' => $style['parts']]
                : ['style' => $style]],
        ]);
    }

    /** @return string the class attribute of the one element carrying `$class` */
    private static function classOf(string $html, string $class): string
    {
        self::assertSame(
            1,
            preg_match('~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~', $html, $m),
            "{$class} in {$html}",
        );
        return $m[1];
    }

    /** @return array<string,mixed> */
    private static function look(): array
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        return [
            'colors' => [
                'surface' => $token('color.black'),
                'text' => $token('color.white'),
                'border' => $token('color.accent'),
            ],
            'border' => ['width' => $choice('thin'), 'style' => $choice('solid')],
            'radius' => $token('radius.sm'),
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ];
    }

    /** @return list<string> */
    private static function lookClasses(): array
    {
        return [
            ClassNames::for('colors.surface', 'color.black'),
            ClassNames::for('colors.text', 'color.white'),
            ClassNames::for('colors.border', 'color.accent'),
            ClassNames::for('border.width', 'thin'),
            ClassNames::for('border.style', 'solid'),
            ClassNames::for('radius', 'radius.sm'),
        ];
    }

    public function testBothBlocksDeclareTheirButtonsLook(): void
    {
        $registry = $this->container()->get(BlockStyleRegistry::class);
        foreach (['mini-cart', 'wishlist-link'] as $type) {
            $caps = $registry->capabilitiesFor($type);
            $paths = ['colors.surface', 'colors.text', 'colors.border', 'border.width', 'radius', 'shadow'];
            foreach ([...$paths, 'spacing.padding.top'] as $path) {
                self::assertTrue($caps->allows($path), "{$type}: {$path}");
            }
        }
        self::assertTrue($registry->capabilitiesFor('wishlist-link')->allows('typography.size'));
    }

    public function testTheMiniCartsLookLandsOnItsToggle(): void
    {
        $html = $this->render('mini-cart', self::look());
        $toggle = self::classOf($html, 'thallo-block-mini-cart__toggle');
        $root = self::classOf($html, 'thallo-block-mini-cart');
        foreach (self::lookClasses() as $class) {
            self::assertStringContainsString($class, $toggle);
            self::assertStringNotContainsString($class, $root);
        }
        self::assertStringContainsString(ClassNames::for('spacing.padding.top', 'spacing.lg'), $root);
    }

    public function testTheWishlistLinksLookLandsOnItsLink(): void
    {
        $size = ['base' => ['type' => 'token', 'value' => 'typography.size.lg']];
        $style = self::look() + ['typography' => ['size' => $size]];
        $html = $this->render('wishlist-link', $style, ['label' => 'Saved']);
        $link = self::classOf($html, 'thallo-block-wishlist-link__link');
        $root = self::classOf($html, 'thallo-block-wishlist-link');
        foreach ([...self::lookClasses(), ClassNames::for('typography.size', 'typography.size.lg')] as $class) {
            self::assertStringContainsString($class, $link);
            self::assertStringNotContainsString($class, $root);
        }
    }

    public function testTheMiniCartsPanelTakesItsOwnLook(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $html = $this->render('mini-cart', ['parts' => ['panel' => [
            'colors' => ['surface' => $token('color.black'), 'text' => $token('color.white')],
            'radius' => $token('radius.sm'),
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ]]]);
        $panel = self::classOf($html, 'thallo-block-mini-cart__panel');
        $own = [
            ClassNames::for('colors.surface', 'color.black'),
            ClassNames::for('colors.text', 'color.white'),
            ClassNames::for('radius', 'radius.sm'),
            ClassNames::for('spacing.padding.top', 'spacing.lg'),
        ];
        foreach ($own as $class) {
            self::assertStringContainsString($class, $panel);
            self::assertStringNotContainsString($class, self::classOf($html, 'thallo-block-mini-cart__toggle'));
        }
    }

    public function testTheLinkedShopStylesheetIsServedInsideTheThemeLayer(): void
    {
        $map = $this->container()->get(ShopAssetMap::class);
        $name = (string) $map->fingerprintedName('shop.css');
        $css = (string) $map->contents($name);
        self::assertStringStartsWith('@layer theme {', $css);
        self::assertStringEndsWith("}\n", $css);
        // The URL's fingerprint is the served bytes', so a cached unlayered copy cannot linger.
        self::assertStringContainsString(substr(hash('sha256', $css), 0, 12), $name);
        // Scripts are served as they are.
        $js = (string) $map->contents((string) $map->fingerprintedName('shop.js'));
        self::assertStringNotContainsString('@layer', substr($js, 0, 40));
    }
}
