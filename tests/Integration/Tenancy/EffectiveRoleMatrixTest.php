<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Authorization\CapabilityCatalog;
use App\Content\Authorization\EffectiveRoleEvaluator;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\RoleMatrix;
use App\Content\Authorization\TenantRoleOverrideRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\Drivers\ArrayCacheDriver;

final class EffectiveRoleMatrixTest extends AppTestCase
{
    public function testTenantDeltaAndOwnerFloor(): void
    {
        $repository = $this->container()->get(TenantRoleOverrideRepository::class);
        $catalog = new CapabilityCatalog();
        $evaluator = new EffectiveRoleEvaluator(new RoleMatrix($this->appContext()), $repository, $catalog);
        $matrix = new EffectiveRoleMatrix(
            $this->appContext(),
            $evaluator,
            $repository,
            $catalog,
            new ArrayCacheDriver(),
        );
        $this->connection()->transaction(fn () => $repository->reconcileRoleOverridesInTransaction(
            'tenantrole04',
            'member',
            ['collections.data.manage'],
            [],
            null,
        ));
        self::assertTrue($matrix->allows('tenantrole04', 'member', 'collections.data.manage'));
        self::assertFalse($matrix->allows('tenantrole05', 'member', 'collections.data.manage'));
        self::assertTrue($matrix->allows('tenantrole04', 'owner', 'tenant.roles.manage'));
        self::assertFalse($matrix->allows('tenantrole04', 'reviewer', 'content.view'));
    }

    public function testFirstMutationMakesPristineCacheEntryUnreachable(): void
    {
        $repository = $this->container()->get(TenantRoleOverrideRepository::class);
        $catalog = new CapabilityCatalog();
        $cache = new ArrayCacheDriver();
        $evaluator = new EffectiveRoleEvaluator(new RoleMatrix($this->appContext()), $repository, $catalog);
        $matrix = new EffectiveRoleMatrix($this->appContext(), $evaluator, $repository, $catalog, $cache);
        self::assertFalse($matrix->allows('tenantrole06', 'member', 'content.publish'));
        $this->connection()->transaction(fn () => $repository->reconcileRoleOverridesInTransaction(
            'tenantrole06',
            'member',
            ['content.publish'],
            [],
            null,
        ));
        self::assertTrue($matrix->allows('tenantrole06', 'member', 'content.publish'));
    }
}
