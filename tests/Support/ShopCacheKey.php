<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Cache\CacheStore;
use Thallo\Commerce\Layouts\ShopLayoutTags;

/**
 * The key the shop page cache stores a page under, as a test must name it (type layouts plan C2): a
 * layout surface's page — the shop home, a category, a product — carries its workspace's current
 * layout generation for that surface, read from the store; every other shop page (the wishlist)
 * carries none.
 */
final class ShopCacheKey
{
    public static function for(
        CacheStore $cache,
        string $tenant,
        string $appearance,
        string $path,
        int $page = 1,
        string $locale = 'en',
        string $theme = 'default',
    ): string {
        $surface = self::surface($path);
        $layout = '';
        if ($surface !== null) {
            $token = $cache->get(ShopLayoutTags::generationKey($surface, $tenant));
            $layout = $surface . 'g' . (is_string($token) ? $token : 'none') . ':';
        }
        return "shop:{$tenant}:{$locale}:{$theme}:{$appearance}:{$page}:{$layout}" . rawurlencode($path);
    }

    /** The layout surface a shop path is a page of, or null. */
    private static function surface(string $path): ?string
    {
        return match (true) {
            $path === '/shop' => 'shop_index',
            str_starts_with($path, '/shop/categories/') => 'shop_category',
            str_starts_with($path, '/shop/products/') => 'product',
            default => null,
        };
    }
}
