<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Setup\Console\SuperuserTransferCommand;
use App\Support\AuthorityMutator;
use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;

final class SuperuserTransferCommandTest extends AppTestCase
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

    public function testTransfersAtomicallyAndCompletedRetryIsIdempotent(): void
    {
        $from = $this->makeUser();
        $to = $this->makeUser();
        self::assertTrue($this->container()->get(AuthorityMutator::class)->assignRole($from, 'superuser'));

        self::assertSame(0, $this->executeCommand($from, $to));
        $authority = $this->container()->get(RoleAuthority::class);
        self::assertFalse($authority->isCanonicalSuperuser($from));
        self::assertTrue($authority->isCanonicalSuperuser($to));
        self::assertSame(0, $this->executeCommand($from, $to));
    }

    public function testRejectsSameUnknownAndInactiveUsers(): void
    {
        $active = $this->makeUser();
        self::assertSame(1, $this->executeCommand($active, $active));
        self::assertSame(1, $this->executeCommand('nope00000000', $active));
        self::assertSame(1, $this->executeCommand($active, $this->makeUser('inactive')));
    }

    private function executeCommand(string $from, string $to): int
    {
        return (new CommandTester($this->container()->get(SuperuserTransferCommand::class)))->execute([
            'from-user-uuid' => $from,
            'to-user-uuid' => $to,
            '--force' => true,
        ]);
    }

    private function makeUser(string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@transfer.test',
            'password' => 'x',
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        return $uuid;
    }
}
