<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Retrofit\DefaultTenant;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

final class SingleStoreTenantTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetState();
    }

    protected function tearDown(): void
    {
        $this->resetState();
        parent::tearDown();
    }

    public function testEnsureIsIdempotentAndResolveUsesPointerWhenDisabled(): void
    {
        $service = $this->container()->get(SingleStoreTenant::class);
        $owner = $this->createUser('single-store@example.com');

        $first = $service->ensure('default', 'Default', $owner);
        self::assertSame($first, $service->ensure('default', 'Default', $owner));
        self::assertSame($first, $service->resolve());
        self::assertSame(1, (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenants')->fetchColumn());
        self::assertSame(
            1,
            (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenant_memberships')->fetchColumn(),
        );
    }

    public function testEnsureParticipatesInOuterTransaction(): void
    {
        $service = $this->container()->get(SingleStoreTenant::class);
        $owner = $this->createUser('rollback@example.com');
        $pdoId = spl_object_id($this->connection()->getPDO());

        try {
            $this->connection()->transaction(function () use ($service, $owner, $pdoId): void {
                self::assertSame($pdoId, spl_object_id($this->connection()->getPDO()));
                $service->ensure('default', 'Default', $owner);
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected rollback sentinel.');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }

        self::assertSame(0, (int) $this->connection()->getPDO()->query('SELECT count(*) FROM tenants')->fetchColumn());
        $this->container()->get(SystemFlags::class)->clearCache();
        self::assertNull($this->container()->get(SystemFlags::class)->defaultTenantUuid());
    }

    public function testDefaultTenantDelegatesToSingleStoreTenant(): void
    {
        $owner = $this->createUser('delegate@example.com');
        $established = $this->container()->get(SingleStoreTenant::class)
            ->ensure('default', 'Default', $owner);

        self::assertSame(
            $established,
            $this->container()->get(DefaultTenant::class)->ensure('default', 'Default', $owner),
        );
    }

    private function createUser(string $email): string
    {
        return $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resetState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('TRUNCATE TABLE tenant_memberships, tenants, users CASCADE');
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        $this->container()->get(SystemFlags::class)->clearCache();
    }
}
