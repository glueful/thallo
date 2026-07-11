<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Contracts\TenantSeedActivator;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Http\Controllers\TenantManagementController;
use Thallo\Tenancy\Runtime\BootstrapTenantCreationGuard;
use Thallo\Tenancy\StarterSeedException;

final class TenantSeedActivationControllerTest extends TestCase
{
    public function testCreateReturnsActiveOnlyAfterSeederSucceeds(): void
    {
        $admin = $this->administration();
        $seeder = new class implements TenantSeedActivator {
            public bool $called = false;

            public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void
            {
                $this->called = true;
            }
        };

        $response = $this->controller($admin, $seeder)->create($this->request());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('active', $body['data']['status']);
        self::assertTrue($seeder->called);
    }

    public function testSeedFailureReturnsRepairableProvisioningResponse(): void
    {
        $admin = $this->administration();
        $seeder = new class implements TenantSeedActivator {
            public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void
            {
                throw new StarterSeedException('block_type:section', new \RuntimeException('failed'));
            }
        };

        $response = $this->controller($admin, $seeder)->create($this->request());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('provisioning', $body['error']['details']['status']);
        self::assertSame('block_type:section', $body['error']['details']['failed_definition']);
        self::assertStringContainsString('thallo:tenant:seed', $body['error']['details']['repair_command']);
    }

    public function testSeedRepairEndpointReturnsActive(): void
    {
        $repair = new class implements TenantSeedRepair {
            public bool $called = false;

            public function repair(string $tenantUuid): void
            {
                $this->called = $tenantUuid === 'tenant000001';
            }
        };
        $controller = $this->controller($this->administration(), $this->successfulSeeder(), $repair);

        $response = $controller->seed('tenant000001');
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('active', $body['data']['tenant']['status']);
        self::assertTrue($repair->called);
    }

    private function controller(
        TenantAdministration $admin,
        TenantSeedActivator $seeder,
        ?TenantSeedRepair $repair = null,
    ): TenantManagementController {
        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $readiness = new class implements TenantRuntimeReadiness {
            public function isReady(ApplicationContext $context): bool
            {
                return true;
            }

            public function mode(ApplicationContext $context): string
            {
                return self::MODE_FULL_RESOLUTION;
            }
        };
        return new TenantManagementController(
            $context,
            new BootstrapTenantCreationGuard($context, $readiness),
            $admin,
            $seeder,
            $repair,
        );
    }

    private function successfulSeeder(): TenantSeedActivator
    {
        return new class implements TenantSeedActivator {
            public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void
            {
            }
        };
    }

    private function request(): Request
    {
        $request = Request::create('/', 'POST', content: json_encode([
            'slug' => 'acme',
            'name' => 'Acme',
        ], JSON_THROW_ON_ERROR));
        $request->attributes->set('user', ['uuid' => 'user00000001']);
        return $request;
    }

    private function administration(): TenantAdministration
    {
        return new class implements TenantAdministration {
            public function create(
                ApplicationContext $c,
                string $slug,
                string $name,
                string $ownerUserUuid,
            ): string {
                return 'tenant000001';
            }

            public function suspend(ApplicationContext $c, string $tenantUuid): void
            {
            }

            public function reactivate(ApplicationContext $c, string $tenantUuid): void
            {
            }

            public function markActive(ApplicationContext $c, string $tenantUuid): void
            {
            }

            public function listTenants(ApplicationContext $c, ?string $status = null): array
            {
                return [];
            }

            public function getTenant(ApplicationContext $c, string $tenantUuid): ?array
            {
                return null;
            }

            public function listTenantsForUser(ApplicationContext $c, string $userUuid): array
            {
                return [];
            }

            public function listMembers(ApplicationContext $c, string $tenantUuid): array
            {
                return [];
            }

            public function addMember(
                ApplicationContext $c,
                string $tenantUuid,
                string $userUuid,
                string $role,
            ): void {
            }

            public function removeMember(ApplicationContext $c, string $tenantUuid, string $userUuid): void
            {
            }

            public function setMemberRole(
                ApplicationContext $c,
                string $tenantUuid,
                string $userUuid,
                string $role,
            ): void {
            }
        };
    }
}
