<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;
use Thallo\Commerce\Layouts\ShopLayoutTags;

/**
 * The shop's layout cache tags (type layouts plan C2): one per commerce surface in the page's
 * header, stored per workspace in the shop cache, so a change purges one workspace's pages of one
 * surface. The product tag keeps C1's value.
 */
final class ShopLayoutTagsTest extends TestCase
{
    public function testEachSurfaceHasItsTag(): void
    {
        self::assertSame(['product', 'shop_index', 'shop_category'], ShopLayoutTags::SURFACES);
        self::assertSame('thallo:shop:layout:product', ShopLayoutTags::pageTag('product'));
        self::assertSame('thallo:shop:layout:shop_index', ShopLayoutTags::pageTag('shop_index'));
        self::assertSame('thallo:shop:layout:shop_category:t1', ShopLayoutTags::tenantTag('shop_category', 't1'));
    }

    public function testAnotherSurfaceHasNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShopLayoutTags::pageTag('entry');
    }
}
