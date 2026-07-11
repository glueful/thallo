<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Http\Controllers\UserAdminController;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class UserAdminContinuityTest extends AppTestCase
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

    public function testSecondSuperuserDeletionIsBlocked(): void
    {
        $first = $this->makeUser('superuser');
        $second = $this->makeUser('superuser');
        $actor = $this->makeUser();
        $controller = $this->container()->get(UserAdminController::class);

        self::assertSame(200, $controller->destroy($this->requestAs($actor), $first)->getStatusCode());
        self::assertSame(403, $controller->destroy($this->requestAs($actor), $second)->getStatusCode());
        self::assertNull($this->deletedAt($second));
    }

    private function makeUser(?string $role = null): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@user-admin.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        if ($role !== null) {
            self::assertTrue($this->container()->get(AegisPermissionProvider::class)->assignRole($uuid, $role));
        }
        return $uuid;
    }

    private function requestAs(string $actorUuid): Request
    {
        $request = Request::create('/v1/admin/users', 'DELETE');
        $request->attributes->set('user', ['uuid' => $actorUuid]);
        return $request;
    }

    private function deletedAt(string $uuid): mixed
    {
        $row = $this->connection()->table('users')->select(['deleted_at'])
            ->where('uuid', '=', $uuid)->first();
        return is_array($row) ? ($row['deleted_at'] ?? null) : null;
    }
}
