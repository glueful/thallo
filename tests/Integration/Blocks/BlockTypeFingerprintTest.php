<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A seeded block type is its definition (starter sync, spec §5): the fingerprint of a definition and
 * of the row it seeded are the same, so a later sync sees the row unchanged and keeps it up to date.
 * Contributed definitions (the shop's blocks, the product page's) carry no starter content; their
 * row stores none — the two must still agree, or every sync after the first reads them as edited.
 */
final class BlockTypeFingerprintTest extends AppTestCase
{
    public function testEverySeededBlockTypeFingerprintsAsItsDefinition(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $kind = $this->container()->get(BlockTypeKind::class);
        $checked = [];
        foreach ($kind->definitions() as $definition) {
            $located = $kind->locateExact($definition->definitionKey);
            self::assertNotNull($located, "{$definition->definitionKey} is seeded");
            self::assertSame(
                $kind->fingerprint($definition),
                $located['fingerprint'],
                "{$definition->sourceId}: the seeded row is its definition",
            );
            $checked[] = $definition->sourceId;
        }
        self::assertContains('thallo-commerce:product-grid', $checked);
        self::assertContains('thallo-commerce:product_buy', $checked);
    }
}
