<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * The shop's pages sit in the theme's page box — its width and its side gutter, the header's — so
 * their content starts where the logo does, as a page's blocks do. A Frame set to Full width
 * releases the box.
 */
final class ShopPageContainerTest extends TestCase
{
    private const PAGES = ['shop-product', 'shop-index', 'shop-category', 'shop-wishlist'];

    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css');
    }

    public function testTheShopsPagesSitInTheThemesBox(): void
    {
        $css = self::css();
        self::assertStringNotContainsString('max-width: 64rem', $css);
        foreach (self::PAGES as $page) {
            self::assertMatchesRegularExpression(
                '~\.' . $page . '\b[^{]*\{[^}]*max-width: var\(--container, 72rem\);[^}]*'
                    . 'padding: 1\.5rem var\(--space-4, 1\.5rem\) 3rem;~',
                $css,
                $page,
            );
        }
    }

    public function testAFullWidthFrameReleasesTheBox(): void
    {
        self::assertMatchesRegularExpression(
            '~\.layout--full :is\(\.shop-product, \.shop-index, \.shop-category\) \{\s*max-width: none;~',
            self::css(),
        );
    }
}
