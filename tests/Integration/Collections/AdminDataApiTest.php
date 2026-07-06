<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Http\Controllers\CollectionDataController;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin data browser reuses CollectionDataController, but the resolved actor is the admin
 * session (not an api key). Drives it through the container with an admin-authenticated request.
 */
final class AdminDataApiTest extends AppTestCase
{
    private const NAME  = 'widgets';
    private const ADMIN = 'admin-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropCollection();
        $this->container()->get(CollectionManager::class)->create([
            'name'   => self::NAME,
            'label'  => 'Widgets',
            'fields' => [['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]]],
        ], 'admin', 'setup');
    }

    protected function tearDown(): void
    {
        $this->dropCollection();
        parent::tearDown();
    }

    public function testAdminCreateStampsAdminActor(): void
    {
        $response = $this->controller()->create($this->adminRequest(['title' => 'First']), self::NAME);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $rows = $this->connection()->table($this->table())->get();
        self::assertCount(1, $rows);
        self::assertSame('admin', $rows[0]['created_by_type']);
        self::assertSame(self::ADMIN, $rows[0]['created_by_id']);
    }

    public function testAdminListReturnsRows(): void
    {
        $this->controller()->create($this->adminRequest(['title' => 'Alpha']), self::NAME);
        $this->controller()->create($this->adminRequest(['title' => 'Beta']), self::NAME);

        $response = $this->controller()->list($this->adminRequest(), self::NAME);
        self::assertSame(200, $response->getStatusCode());

        $content = (string) $response->getContent();
        self::assertStringContainsString('Alpha', $content);
        self::assertStringContainsString('Beta', $content);
    }

    public function testAdminUpdateAndDeleteRoundTrip(): void
    {
        $this->controller()->create($this->adminRequest(['title' => 'Original']), self::NAME);
        $uuid = (string) $this->connection()->table($this->table())->get()[0]['uuid'];

        $update = $this->controller()->update($this->adminRequest(['title' => 'Updated']), self::NAME, $uuid);
        self::assertSame(200, $update->getStatusCode(), (string) $update->getContent());

        $delete = $this->controller()->delete($this->adminRequest(), self::NAME, $uuid);
        self::assertSame(204, $delete->getStatusCode(), (string) $delete->getContent());
        self::assertCount(0, $this->connection()->table($this->table())->get());
    }

    private function controller(): CollectionDataController
    {
        return $this->container()->get(CollectionDataController::class);
    }

    /** @param array<string, mixed>|null $body */
    private function adminRequest(?array $body = null): Request
    {
        $request = Request::create(
            '/',
            $body === null ? 'GET' : 'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body === null ? null : (string) json_encode($body),
        );
        $request->attributes->set('user', ['uuid' => self::ADMIN, 'roles' => ['administrator']]);

        return $request;
    }

    private function table(): string
    {
        return CollectionManager::tableNameFor(self::NAME);
    }

    private function dropCollection(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        if ($schema->hasTable($this->table())) {
            $schema->dropTableIfExists($this->table());
        }
        $this->connection()->table('collection_definitions')->where('name', self::NAME)->delete();
    }
}
