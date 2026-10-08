<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Core\Content\Blocks\BlockTypeStylePaths;
use Thallo\Core\Tests\Support\AppTestCase;

/** Product grid spec §7.1: the seven parts, and resting opacity beside hover opacity. */
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
        self::assertSame(['card', 'image', 'title', 'price', 'meta', 'button', 'badge'], array_keys($parts));
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
        self::assertContains('typography.size', $parts['price']);
    }
}
