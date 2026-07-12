<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Authorization\RoleOverrideException;
use App\Content\Authorization\TenantRoleOverrideRepository;
use App\Tests\Support\AppTestCase;

final class TenantRoleOverrideRepositoryTest extends AppTestCase
{
    private TenantRoleOverrideRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->container()->get(TenantRoleOverrideRepository::class);
    }

    public function testFirstMutationRotatesVersionAndReconcileBumpsOnce(): void
    {
        $tenant = 'tenantrole01';
        self::assertSame(0, $this->repository->policyVersion($tenant));
        $first = $this->connection()->transaction(fn () => $this->repository
            ->reconcileRoleOverridesInTransaction(
                $tenant,
                'member',
                ['content.publish', 'collections.data.manage'],
                [],
                'actor0000001',
            ));
        self::assertSame(1, $first['version']);
        self::assertCount(2, $first['set']);
        $second = $this->connection()->transaction(fn () => $this->repository
            ->reconcileRoleOverridesInTransaction($tenant, 'member', [], [], 'actor0000001'));
        self::assertSame(2, $second['version']);
        self::assertCount(2, $second['cleared']);
    }

    public function testInvalidDesiredSetIsAtomic(): void
    {
        try {
            $this->connection()->transaction(fn () => $this->repository
                ->reconcileRoleOverridesInTransaction(
                    'tenantrole02',
                    'owner',
                    ['content.publish'],
                    ['tenant.roles.manage'],
                    null,
                ));
            self::fail('Expected validation failure.');
        } catch (RoleOverrideException $exception) {
            self::assertArrayHasKey('tenant.roles.manage', $exception->errors);
        }
        self::assertSame(0, $this->repository->policyVersion('tenantrole02'));
        self::assertSame([], $this->repository->overridesFor('tenantrole02'));
    }

    public function testCheckpointOneRejectsCustomRoleSlug(): void
    {
        $this->expectException(RoleOverrideException::class);
        $this->connection()->transaction(fn () => $this->repository
            ->reconcileRoleOverridesInTransaction(
                'tenantrole03',
                'reviewer',
                ['content.view'],
                [],
                null,
            ));
    }
}
