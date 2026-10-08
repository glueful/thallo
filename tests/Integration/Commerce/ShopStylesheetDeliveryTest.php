<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * Product grid spec §3.4: the storefront stylesheet arrives only through the theme layer
 * (ShopStylesheetContributor, inside `@layer theme` — StorefrontInertnessTest proves that half). A
 * raw link would load a second, UNLAYERED copy that beats every layer, authored Style tab settings
 * included, on any page holding the block or page that linked it.
 */
final class ShopStylesheetDeliveryTest extends TestCase
{
    public function testNoThalloTemplateLinksTheShopStylesheet(): void
    {
        $root = dirname(__DIR__, 3) . '/packages';
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (
                $file->isFile() && str_ends_with($file->getFilename(), '.twig')
                && str_contains((string) file_get_contents($file->getPathname()), 'shop/shop.css')
            ) {
                $hits[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($hits);
        self::assertSame([], $hits);
    }
}
