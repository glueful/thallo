<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Http\Controllers\AssignableRolesController;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class AssignableRolesEndpointTest extends AppTestCase
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

    public function testSuperuserSeesWorkspaceManagerButAdministratorDoesNot(): void
    {
        $controller = $this->container()->get(AssignableRolesController::class);
        $superRows = $this->rows($controller->index($this->requestAs($this->makeUser('superuser'))));
        self::assertArrayHasKey('workspace_manager', $superRows);
        self::assertArrayNotHasKey('superuser', $superRows);

        $adminRows = $this->rows($controller->index($this->requestAs($this->makeUser('administrator'))));
        self::assertArrayNotHasKey('workspace_manager', $adminRows);
    }

    public function testAssignedProtectedRolesAreReturnedLockedInEditMode(): void
    {
        $controller = $this->container()->get(AssignableRolesController::class);
        $admin = $this->makeUser('administrator');
        foreach (['workspace_manager', 'superuser'] as $protected) {
            $rows = $this->rows($controller->index(
                $this->requestAs($admin, $this->makeUser($protected))
            ));
            self::assertTrue($rows[$protected]['assigned']);
            self::assertFalse($rows[$protected]['assignable']);
            self::assertFalse($rows[$protected]['removable']);
        }
    }

    public function testUnknownEditTargetReturnsNotFound(): void
    {
        $response = $this->container()->get(AssignableRolesController::class)->index(
            $this->requestAs($this->makeUser('administrator'), 'nope00000000')
        );
        self::assertSame(404, $response->getStatusCode());
    }

    private function makeUser(string ...$roles): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@picker.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        foreach ($roles as $role) {
            self::assertTrue($aegis->assignRole($uuid, $role));
        }
        return $uuid;
    }

    private function requestAs(string $actorUuid, ?string $targetUuid = null): Request
    {
        $request = Request::create('/v1/admin/users/assignable-roles', 'GET', array_filter([
            'target_uuid' => $targetUuid,
        ]));
        $request->attributes->set('user', ['uuid' => $actorUuid]);
        return $request;
    }

    /** @return array<string,array<string,mixed>> */
    private function rows(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $payload = json_decode((string) $response->getContent(), true);
        return array_column($payload['data']['roles'] ?? [], null, 'slug');
    }
}
