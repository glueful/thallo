<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Indexing;

use Thallo\Core\Content\Indexing\FilterIndexPlanner;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use PHPUnit\Framework\TestCase;

final class MembershipIndexPlanTest extends TestCase
{
    public function testFilterableReferencePlansGinMembershipIndex(): void
    {
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                'multiple' => true, 'filterable' => true],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
        $plan = (new FilterIndexPlanner())->desiredIndexes($schema, 'type00000001');

        $byField = [];
        foreach ($plan as $p) {
            $byField[$p['field']] = $p;
        }
        self::assertArrayHasKey('category', $byField);
        self::assertSame('gin', $byField['category']['method']);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category')", $byField['category']['expression']);
        self::assertSame('reference', $byField['category']['filter_type']);
        self::assertSame('btree', $byField['price']['method'] ?? 'btree');
    }
}
