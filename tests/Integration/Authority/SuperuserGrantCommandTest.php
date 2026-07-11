<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Setup\Console\SuperuserGrantCommand;
use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;

final class SuperuserGrantCommandTest extends AppTestCase
{
    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        foreach ($this->users as $uuid) {
            $this->connection()->table('user_roles')->where('user_uuid', '=', $uuid)->delete();
            $this->connection()->table('users')->where('uuid', '=', $uuid)->delete();
        }
        parent::tearDown();
    }

    public function testGrantIsIdempotentAndIncludesAdministrator(): void
    {
        $uuid = $this->makeUser();
        self::assertSame(0, $this->executeCommand($uuid));
        self::assertSame(0, $this->executeCommand($uuid));
        self::assertTrue($this->container()->get(RoleAuthority::class)->isCanonicalSuperuser($uuid));
        self::assertSame(1, $this->roleCount($uuid, 'administrator'));
    }

    public function testUnknownAndInactiveUsersFail(): void
    {
        self::assertSame(1, $this->executeCommand('nope00000000'));
        self::assertSame(1, $this->executeCommand($this->makeUser('inactive')));
    }

    private function executeCommand(string $uuid): int
    {
        return (new CommandTester($this->container()->get(SuperuserGrantCommand::class)))
            ->execute(['user-uuid' => $uuid, '--force' => true]);
    }

    private function makeUser(string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@grant.test',
            'password' => 'x',
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        return $uuid;
    }

    private function roleCount(string $userUuid, string $slug): int
    {
        return $this->connection()->table('user_roles AS ur')
            ->join('roles AS r', 'r.uuid', '=', 'ur.role_uuid')
            ->where('ur.user_uuid', '=', $userUuid)
            ->where('r.slug', '=', $slug)
            ->count();
    }
}
