<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Http\Controllers\ApiKeyAdminController;
use App\Http\DTOs\UpdateApiKeyScopesData;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;

/**
 * The PATCH /v1/admin/api-keys/{uuid}/scopes endpoint replaces a key's scopes in place (no key
 * rotation) — the surface the collections scopes panel drives.
 */
final class ApiKeyScopesTest extends AppTestCase
{
    public function testUpdateScopesReplacesTheKeyScopesInPlace(): void
    {
        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => 'u-1',
            'name' => 'Scopes Key',
            'scopes' => ['posts.read'],
        ]);
        $uuid = $created['key']->uuid;

        $controller = $this->container()->get(ApiKeyAdminController::class);
        $response = $controller->updateScopes(
            new UpdateApiKeyScopesData(scopes: ['posts.read', 'posts.write']),
            $uuid,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $row = $this->connection()->table('api_keys')->where('uuid', $uuid)->get()[0] ?? null;
        self::assertNotNull($row);
        self::assertSame(['posts.read', 'posts.write'], json_decode((string) $row['scopes'], true));
    }

    public function testUpdateScopesOnUnknownKeyReturns404(): void
    {
        $controller = $this->container()->get(ApiKeyAdminController::class);
        $response = $controller->updateScopes(new UpdateApiKeyScopesData(scopes: []), 'no-such-key');

        self::assertSame(404, $response->getStatusCode());
    }
}
