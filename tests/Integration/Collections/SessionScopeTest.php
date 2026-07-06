<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;
use Thallo\Collections\CollectionManager;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The session branch of CollectionScopeMiddleware: a scoped write is authorized by a logged-in
 * user's Aegis permission (collections.{collection}.write on resource 'thallo'), not only by an
 * api-key scope.
 *
 * The public routes carry no auth middleware, so the gate authenticates on demand; here we pre-set
 * the `user` request attribute (the gate reads it before calling AuthenticationManager), which lets
 * us exercise the permission check without minting a JWT.
 */
final class SessionScopeTest extends AppTestCase
{
    private const COL = 'widgets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropCollection();
        $this->container()->get(CollectionManager::class)->create([
            'name' => self::COL,
            'label' => 'Widgets',
            'fields' => [['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]]],
        ], 'admin', 'setup');
    }

    protected function tearDown(): void
    {
        $this->dropCollection();
        parent::tearDown();
    }

    public function testSessionUserWithPermissionCanWriteScopedCollection(): void
    {
        // The Aegis provider grants by looking up the permission slug, so it must exist first.
        $this->seedPermission('collections.' . self::COL . '.write');
        $this->permissions()->assignPermission('u-author', 'collections.' . self::COL . '.write', 'thallo');
        self::assertTrue(
            $this->permissions()->can('u-author', 'collections.' . self::COL . '.write', 'thallo'),
            'the granted permission should make can() true',
        );

        $response = $this->sessionRequest(
            'POST',
            '/v1/collections/' . self::COL,
            ['uuid' => 'u-author', 'roles' => []],
            ['title' => 'Hello'],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testSessionUserWithoutPermissionGets403(): void
    {
        $response = $this->sessionRequest(
            'POST',
            '/v1/collections/' . self::COL,
            ['uuid' => 'u-noperm', 'roles' => []],
            ['title' => 'Hello'],
        );

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAnonymousWriteStillGets403(): void
    {
        $response = $this->sessionRequest('POST', '/v1/collections/' . self::COL, null, ['title' => 'Hello']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    private function permissions(): PermissionManager
    {
        // The middleware resolves PermissionManager under the 'permission.manager' container id.
        $manager = $this->container()->get('permission.manager');
        self::assertInstanceOf(PermissionManager::class, $manager);

        return $manager;
    }

    /** @param array<string, mixed>|null $user @param array<string, mixed>|null $body */
    private function sessionRequest(string $method, string $path, ?array $user, ?array $body = null): HttpResponse
    {
        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body === null ? null : (string) json_encode($body),
        );
        if ($user !== null) {
            $request->attributes->set('user', $user);
        }

        return $this->handle($request);
    }

    private function seedPermission(string $slug): void
    {
        if ($this->connection()->table('permissions')->where('slug', $slug)->get() !== []) {
            return;
        }
        $this->connection()->table('permissions')->insert([
            'uuid' => Utils::generateNanoID(),
            'slug' => $slug,
            'name' => $slug,
            'category' => 'collections',
            'description' => $slug,
            'is_system' => true,
        ]);
    }

    private function dropCollection(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $table = CollectionManager::tableNameFor(self::COL);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', self::COL)->delete();
    }
}
