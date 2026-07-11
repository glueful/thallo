<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\AuthorityContinuityGuard;
use App\Support\AuthorityMutator;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class ConnectionParticipationTest extends AppTestCase
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

    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@transaction.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        return $uuid;
    }

    public function testEveryAuthorityMutationParticipantRollsBack(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $mutator = $this->container()->get(AuthorityMutator::class);
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $assigned = $this->makeUser();
        $revoked = $this->makeUser();
        $updated = $this->makeUser();
        $deleted = $this->makeUser();
        self::assertTrue($aegis->assignRole($revoked, 'editor'));

        try {
            $guard->runExclusive(function () use ($mutator, $assigned, $revoked, $updated, $deleted): void {
                self::assertTrue($mutator->assignRole($assigned, 'editor'));
                self::assertTrue($mutator->revokeRole($revoked, 'editor'));
                self::assertTrue($mutator->updateUser($updated, ['status' => 'inactive']));
                self::assertTrue($mutator->softDeleteUser($deleted));
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $role = $this->connection()->table('roles')->select(['uuid'])->where('slug', '=', 'editor')->first();
        self::assertIsArray($role);
        $roleUuid = (string) $role['uuid'];
        self::assertSame(0, $this->assignmentCount($assigned, $roleUuid));
        self::assertSame(1, $this->assignmentCount($revoked, $roleUuid));
        self::assertSame('active', $this->userColumn($updated, 'status'));
        self::assertNull($this->userColumn($deleted, 'deleted_at'));
    }

    private function assignmentCount(string $userUuid, string $roleUuid): int
    {
        return $this->connection()->table('user_roles')
            ->where('user_uuid', '=', $userUuid)
            ->where('role_uuid', '=', $roleUuid)
            ->count();
    }

    private function userColumn(string $userUuid, string $column): mixed
    {
        $row = $this->connection()->table('users')->select([$column])
            ->where('uuid', '=', $userUuid)->first();
        return is_array($row) ? ($row[$column] ?? null) : null;
    }
}
