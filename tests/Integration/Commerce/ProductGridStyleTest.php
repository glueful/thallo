<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Core\Content\Blocks\BlockTypeStylePaths;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §7.1: the nine parts, and resting opacity beside hover opacity. */
final class ProductGridStyleTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private function paths(): array
    {
        foreach ((new ShopBlockTypesContributor())->blockTypeDefinitions() as $d) {
            if ($d->slug === ShopBlockTypesContributor::SLUG_PRODUCT_GRID) {
                return BlockTypeStylePaths::for([
                    'style_capabilities' => $d->styleCapabilities,
                    'style_targets' => $d->styleTargets,
                ]);
            }
        }
        self::fail('no product grid');
    }

    public function testThePublishedStylePaths(): void
    {
        $parts = $this->paths()['parts'];
        self::assertSame(
            ['card', 'image', 'details', 'title', 'price', 'meta', 'button', 'wishlist', 'badge'],
            array_keys($parts),
        );
        // The heart on the picture styles apart from the cart button.
        foreach (['colors.surface', 'colors.text', 'radius', 'opacity', 'hover.colors.surface'] as $path) {
            self::assertContains($path, $parts['wishlist'], $path);
        }
        foreach (['card', 'button'] as $part) {
            self::assertContains('opacity', $parts[$part], $part);
            self::assertContains('hover.opacity', $parts[$part], $part);
            self::assertContains('hover.colors.surface', $parts[$part], $part);
        }
        self::assertContains('hover.colors.text', $parts['button']);
        self::assertContains('hover.colors.text', $parts['title']);
        self::assertNotContains('opacity', $parts['title']);
        foreach (['image', 'price', 'meta', 'badge'] as $part) {
            $hover = array_filter($parts[$part], static fn (string $p): bool => str_starts_with($p, 'hover.'));
            self::assertSame([], array_values($hover), $part);
        }
        self::assertContains('radius', $parts['image']);
        // The image frame's tint is the part's own background, so a white card can have a white frame.
        self::assertContains('colors.surface', $parts['image']);
        // The text under the picture pads apart from the card, so the picture can keep the card's edges.
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertContains('spacing.padding.' . $side, $parts['details'], $side);
        }
        self::assertContains('typography.size', $parts['price']);
    }

    public function testTheGridsImageFrameIsTransparentUntilTheImagePartSetsABackground(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css');
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product-grid \.shop-grid__media \{[^}]*background: transparent;~',
            $css,
        );
    }

    public function testASavedHeartFillsSoItsStateSurvivesTheWishlistPartsColours(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-commerce/assets/shop.css');
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product-grid \.shop-grid__action--wishlist path \{'
                . '[^}]*fill: none;[^}]*stroke: currentColor;~',
            $css,
            'an outline heart until saved',
        );
        self::assertMatchesRegularExpression(
            '~\.thallo-block-product-grid \.shop-grid__action--wishlist\[aria-pressed="true"\] path \{'
                . '[^}]*fill: currentColor;~',
            $css,
            'a filled heart once saved',
        );
    }
}
