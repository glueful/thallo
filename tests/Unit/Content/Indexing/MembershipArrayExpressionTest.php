<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Indexing;

use Thallo\Core\Content\Indexing\FieldSqlExpression;
use PHPUnit\Framework\TestCase;

final class MembershipArrayExpressionTest extends TestCase
{
    public function testMembershipArrayNormalizesToJsonbArray(): void
    {
        $sql = FieldSqlExpression::membershipArray('category');
        self::assertStringContainsString("fields -> 'category' IS NULL", $sql);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category') = 'array'", $sql);
        self::assertStringContainsString("(fields -> 'category') || '[]'::jsonb", $sql);
        self::assertStringContainsString("'[]'::jsonb", $sql);
    }
}
