<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;
use Thallo\Account\Blocks\AccountBlockTypesContributor;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;

/**
 * Inside a Container, a block sizes itself through the item settings (basis, span, grow, shrink,
 * align self) that `layout.item` opens. The shop and account blocks did not declare it, so they
 * were the only blocks a layout could not size.
 */
final class PackBlockLayoutItemTest extends TestCase
{
    public function testEveryShopAndAccountBlockCanBeSizedInALayout(): void
    {
        $definitions = [
            ...(new ShopBlockTypesContributor())->blockTypeDefinitions(),
            ...(new AccountBlockTypesContributor())->blockTypeDefinitions(),
        ];
        self::assertCount(9, $definitions);

        foreach ($definitions as $definition) {
            self::assertContains('layout.item', $definition->styleCapabilities, $definition->slug);
            self::assertSame('root', $definition->styleTargets['map']['layout.item'] ?? null, $definition->slug);
        }
    }
}
