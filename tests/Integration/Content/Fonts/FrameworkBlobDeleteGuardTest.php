<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The framework's own `DELETE /v1/blobs/{uuid}` keeps a font library file too (final review): the
 * media library already refuses it, and this door must not delete it behind the library's back.
 */
final class FrameworkBlobDeleteGuardTest extends AppTestCase
{
    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        $this->user = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $this->user, 'username' => 'blob_' . substr($this->user, 0, 6),
            'email' => $this->user . '@example.test', 'password' => 'x', 'status' => 'active',
            'two_factor_enabled' => false, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->permissions()->setProvider(new InMemoryPermissionProvider([
            $this->user => ['content.manage', 'media.delete', 'uploads.delete'],
        ]));
    }

    protected function tearDown(): void
    {
        $this->permissions()->clearProvider();
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
        parent::tearDown();
    }

    private function permissions(): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = $this->container()->get('permission.manager');
        return $manager;
    }

    private function blob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => $uuid . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => $this->user, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function delete(string $uuid): \Symfony\Component\HttpFoundation\Response
    {
        $key = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->user, 'name' => 'blob-guard', 'scopes' => ['*'],
        ])['plain'];
        return $this->handle(Request::create('/v1/blobs/' . $uuid, 'DELETE', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $key,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
        ]));
    }

    public function testALibraryFontFileIsRefusedAndKept(): void
    {
        $this->blob('fontguard001');
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('font_families')->insert([
            'id' => 'Gd3dE5fG7hJ9', 'name' => 'Guarded', 'fallback' => 'serif', 'removed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->table('font_faces')->insert([
            'id' => 'faceguard001', 'family_id' => 'Gd3dE5fG7hJ9', 'blob_uuid' => 'fontguard001',
            'weight_min' => 400, 'weight_max' => 400, 'italic' => false, 'variable' => false,
            'unknown' => false, 'created_at' => $now,
        ]);
        $res = $this->delete('fontguard001');
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        self::assertStringContainsString('This file is a font in the library (Guarded)', (string) $res->getContent());
        $row = $this->connection()->table('blobs')->where('uuid', '=', 'fontguard001')->first();
        self::assertSame('active', $row['status'] ?? null);
    }

    public function testAnyOtherFileIsLeftToTheFramework(): void
    {
        $this->blob('plainblob001');
        self::assertNotSame(409, $this->delete('plainblob001')->getStatusCode());
    }
}
