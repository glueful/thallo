<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\LayoutTypeShapes;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The saved-section controller as the application builds it, deciding with the real permission
 * authority (sections and templates design §5): an editor with only one of the two rights saves
 * only the sections of its scope, and one who may only view saves none. The routes let every editor
 * reach it, so this wiring is what stands between a viewer and the library.
 */
final class SavedSectionAuthorityTest extends AppTestCase
{
    use LayoutTypeShapes;
    use SyncsBlockStyleDeclarations;

    /** @var list<string> */
    private array $userUuids = [];

    /** @var list<string> */
    private array $roleUuids = [];

    private const COVER = [
        'id' => 'cov000000009', 'type' => 'entry_cover', 'data' => ['field' => 'cover', 'aspect' => '16:9'],
        'settings' => [],
    ];

    private const HEADING = [
        'id' => 'head00000009', 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->createLayoutTypeShapes();
    }

    protected function tearDown(): void
    {
        $db = $this->connection();
        if ($this->userUuids !== []) {
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }

    /** @param list<string> $permissions a user holding exactly these, through one role */
    private function userWith(array $permissions): string
    {
        $user = Utils::generateNanoID(12);
        $this->userUuids[] = $user;
        $slug = 'ssauth_' . strtolower(Utils::generateNanoID(6));
        $role = Utils::generateNanoID(12);
        $this->roleUuids[] = $role;
        $this->connection()->table('roles')->insert([
            'uuid' => $role, 'name' => $slug, 'slug' => $slug, 'description' => 'saved-section authority test',
            'level' => 30, 'is_system' => false, 'status' => 'active',
        ]);
        $repo = new PermissionRepository($this->connection());
        $grants = new RolePermissionRepository($this->connection());
        foreach ($permissions as $permission) {
            $row = $repo->findPermissionBySlug($permission);
            self::assertNotNull($row, "the permission {$permission} is seeded");
            $grants->assignPermissionToRole($role, $row->getUuid(), []);
        }
        self::assertTrue($this->provider()->assignRole($user, $slug));
        $this->provider()->invalidateAllCache();
        return $user;
    }

    private function as(string $user): Request
    {
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => $user, 'roles' => [], 'scopes' => []]);
        return $request;
    }

    private function controller(): SavedSectionController
    {
        return $this->container()->get(SavedSectionController::class);
    }

    private function saveLayout(string $user): int
    {
        $input = new SaveSectionData(
            name: 'Cover',
            block: self::COVER,
            scope: 'layout',
            surface: 'entry',
            target: 'lp_body',
        );
        return $this->controller()->store($input, $this->as($user))->getStatusCode();
    }

    private function savePage(string $user): int
    {
        return $this->controller()->store(new SaveSectionData(name: 'Hi', block: self::HEADING), $this->as($user))
            ->getStatusCode();
    }

    public function testAContentEditorSavesPageSectionsOnly(): void
    {
        $editor = $this->userWith(['content.view', 'content.manage']);
        self::assertSame(201, $this->savePage($editor));
        self::assertSame(403, $this->saveLayout($editor));
    }

    public function testALayoutEditorSavesLayoutSectionsOnly(): void
    {
        $designer = $this->userWith(['content.view', 'templates.manage']);
        self::assertSame(201, $this->saveLayout($designer));
        self::assertSame(403, $this->savePage($designer));
    }

    public function testAViewerSavesNothing(): void
    {
        $viewer = $this->userWith(['content.view']);
        self::assertSame(403, $this->savePage($viewer));
        self::assertSame(403, $this->saveLayout($viewer));
    }
}
