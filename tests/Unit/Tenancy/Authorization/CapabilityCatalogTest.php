<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\CapabilityCatalog;
use App\Content\Authorization\PermissionImplicationSource;
use App\Tests\Support\AppTestCase;

final class CapabilityCatalogTest extends AppTestCase
{
    public function testCatalogCoversMatrixAndExcludesPlatformCapabilities(): void
    {
        $catalog = new CapabilityCatalog();
        foreach ((array) config($this->appContext(), 'tenancy.role_matrix', []) as $capabilities) {
            foreach ((array) $capabilities as $capability) {
                self::assertTrue($catalog->has((string) $capability), (string) $capability);
            }
        }
        self::assertFalse($catalog->has('tenancy.manage'));
        self::assertFalse($catalog->has('tenancy.access_any'));
        self::assertSame(['tenant.roles.manage', 'tenant.members.manage'], $catalog->ownerFloor());
    }

    public function testPolicyHashIsDeterministic(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame(
            $catalog->baselinePolicyHash($this->appContext()),
            $catalog->baselinePolicyHash($this->appContext()),
        );
    }

    public function testCatalogHasCommerceView(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertTrue($catalog->has('commerce.view'));
    }

    public function testCommerceManageLabelIsRenamed(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame('Manage commerce', $catalog->all()['commerce.manage']['label']);
    }

    public function testCatalogIsAPermissionImplicationSource(): void
    {
        self::assertInstanceOf(PermissionImplicationSource::class, new CapabilityCatalog());
    }

    public function testSatisfiersForCommerceViewIncludesTheImplyingGrant(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame(['commerce.view', 'commerce.manage'], $catalog->satisfiersFor('commerce.view'));
    }

    public function testSatisfiersForCommerceManageIsOnlyItself(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame(['commerce.manage'], $catalog->satisfiersFor('commerce.manage'));
    }

    public function testSatisfiersForAnUnknownPermissionIsIdentity(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame(['tenancy.access_any'], $catalog->satisfiersFor('tenancy.access_any'));
    }

    public function testImplicationCyclesAreRejected(): void
    {
        $this->expectException(\LogicException::class);

        CapabilityCatalog::computeSatisfiers([
            'a.one' => ['label' => 'A', 'group' => 'G', 'platform_only' => false, 'implies' => ['a.two']],
            'a.two' => ['label' => 'B', 'group' => 'G', 'platform_only' => false, 'implies' => ['a.one']],
        ], 'a.one');
    }

    public function testUnknownImplicationTargetsAreRejected(): void
    {
        $this->expectException(\LogicException::class);

        CapabilityCatalog::computeSatisfiers([
            'a.one' => ['label' => 'A', 'group' => 'G', 'platform_only' => false, 'implies' => ['a.ghost']],
        ], 'a.one');
    }
}
