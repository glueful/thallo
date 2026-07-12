<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Tenancy\Console\SingleStoreRepairCommand;
use Thallo\Tenancy\System\SystemFlags;

final class SingleStoreRepairCommandTest extends AppTestCase
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

    public function testCommandRepairsIdempotentlyAndValidatesOwner(): void
    {
        $missing = $this->tester();
        self::assertSame(1, $missing->execute(['--owner' => 'missing-user'], ['interactive' => false]));

        $owner = $this->container()->get(UserRepository::class)->create([
            'username' => 'repair@example.com',
            'email' => 'repair@example.com',
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
        $first = $this->tester();
        self::assertSame(0, $first->execute(['--owner' => $owner], ['interactive' => false]));
        $uuid = $this->container()->get(SystemFlags::class)->defaultTenantUuid();
        self::assertNotNull($uuid);

        $second = $this->tester();
        self::assertSame(0, $second->execute(['--owner' => $owner], ['interactive' => false]));
        self::assertSame($uuid, $this->container()->get(SystemFlags::class)->defaultTenantUuid());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SingleStoreRepairCommand($this->container(), $this->appContext()));
    }

    private function resetState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('TRUNCATE TABLE tenant_memberships, tenants, users CASCADE');
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        $this->container()->get(SystemFlags::class)->clearCache();
    }
}
