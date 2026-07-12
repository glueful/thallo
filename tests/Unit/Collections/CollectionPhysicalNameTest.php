<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use Thallo\Collections\Schema\CollectionPhysicalName;

final class CollectionPhysicalNameTest extends TestCase
{
    public function testTenantTokenIsDeterministicLowercaseBase32(): void
    {
        $token = CollectionPhysicalName::tenantToken('tenantAAAAAA');
        self::assertSame($token, CollectionPhysicalName::tenantToken('tenantAAAAAA'));
        self::assertMatchesRegularExpression('/^[a-z2-7]{10}$/', $token);
    }

    public function testGeneratedNameIsBoundedAndTenantSpecific(): void
    {
        $name = CollectionPhysicalName::generate('tenantAAAAAA');
        self::assertTrue(CollectionPhysicalName::isValid($name));
        self::assertLessThanOrEqual(63, strlen($name));
        self::assertTrue(CollectionPhysicalName::belongsToTenant($name, 'tenantAAAAAA'));
        self::assertFalse(CollectionPhysicalName::belongsToTenant($name, 'tenantBBBBBB'));
    }

    public function testIndexNameIsDeterministicAndBounded(): void
    {
        $name = 'tc_aaaaaaaaaa_bbbbbbbbbbbb';
        $index = CollectionPhysicalName::indexName($name, 'a_very_long_field_name', 'unique');
        self::assertSame($index, CollectionPhysicalName::indexName($name, 'a_very_long_field_name', 'unique'));
        self::assertLessThanOrEqual(63, strlen($index));
        self::assertNotSame($index, CollectionPhysicalName::indexName($name, 'another_field', 'unique'));
    }
}
