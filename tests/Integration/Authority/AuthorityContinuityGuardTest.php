<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\AuthorityContinuityGuard;
use App\Support\RoleAssignmentException;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class AuthorityContinuityGuardTest extends AppTestCase
{
    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        foreach ($this->users as $uuid) {
            $this->connection()->table('user_roles')->where('user_uuid', '=', $uuid)->delete();
            $this->connection()->table('users')->where('uuid', '=', $uuid)->delete();
        }
        $this->users = [];
        parent::tearDown();
    }

    private function makeUser(string $role): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@continuity.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        self::assertTrue($this->container()->get(AegisPermissionProvider::class)->assignRole($uuid, $role));
        return $uuid;
    }

    public function testBlocksDeactivatingSoleSuperuser(): void
    {
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $sole = $this->makeUser('superuser');
        $this->expectException(RoleAssignmentException::class);
        $guard->runExclusive(
            fn () => $guard->assertPreservesAuthority('actor0000001', $sole, [], true, 'deactivate')
        );
    }

    public function testAllowsRemovingManagerWhenAnotherAccessHolderExists(): void
    {
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $this->makeUser('superuser');
        $target = $this->makeUser('workspace_manager');
        $guard->runExclusive(
            fn () => $guard->assertPreservesAuthority(
                'actor0000001',
                $target,
                ['workspace_manager'],
                false,
                'roles_sync',
            )
        );
        self::addToAssertionCount(1);
    }

    public function testAdvisoryLockSerializesIndependentConnections(): void
    {
        $participant = $this->connection()->newPdo();
        $contender = $this->connection()->newPdo();
        $sql = "SELECT pg_try_advisory_xact_lock(hashtextextended('thallo:authority', 0))";
        $participant->beginTransaction();
        $contender->beginTransaction();
        try {
            self::assertTrue((bool) $participant->query($sql)->fetchColumn());
            self::assertFalse((bool) $contender->query($sql)->fetchColumn());
            $participant->commit();
            self::assertTrue((bool) $contender->query($sql)->fetchColumn());
        } finally {
            if ($participant->inTransaction()) {
                $participant->rollBack();
            }
            if ($contender->inTransaction()) {
                $contender->rollBack();
            }
        }
    }
}
