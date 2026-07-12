<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;
use Thallo\Tenancy\Http\Middleware\CollectionsTenantBindingMiddleware;

final class CollectionsTenantBindingMiddlewareTest extends AppTestCase
{
    private const TENANT = 'tenantBind01';
    private const KEY = 'apiKeyBind01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec(
            'TRUNCATE TABLE thallo_tenant_api_key_bindings, api_keys, tenant_memberships, tenants CASCADE',
        );
        $now = date('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => self::TENANT,
            'slug' => 'bound-tenant',
            'name' => 'Bound tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->table('api_keys')->insert([
            'uuid' => self::KEY,
            'user_uuid' => 'bindingUser1',
            'name' => 'Bound key',
            'key_prefix' => 'bound-key',
            'key_hash' => hash('sha256', self::KEY),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->container()->get(TenantApiKeyBindingRepository::class)->bind(self::KEY, self::TENANT);
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)
            ->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            'TRUNCATE TABLE thallo_tenant_api_key_bindings, api_keys, tenant_memberships, tenants CASCADE',
        );
        parent::tearDown();
    }

    public function testBoundKeyRunsInsideItsTenantAndRejectsConflictingHeader(): void
    {
        $request = Request::create('/v1/collections/products');
        $request->attributes->set('api_key_uuid', self::KEY);
        $result = $this->middleware()->handle($request, static fn (): Response => new Response('passed'));
        self::assertSame('passed', $result->getContent());

        $conflict = Request::create('/v1/collections/products', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'otherTenant1',
        ]);
        $conflict->attributes->set('api_key_uuid', self::KEY);
        self::assertSame(403, $this->middleware()->handle($conflict, fn () => new Response())->getStatusCode());
    }

    public function testAnonymousHeaderAndUnboundKeyAreRejected(): void
    {
        $anonymous = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => self::TENANT]);
        self::assertSame(403, $this->middleware()->handle($anonymous, fn () => new Response())->getStatusCode());

        $unbound = Request::create('/');
        $unbound->attributes->set('api_key_uuid', 'otherKey0001');
        self::assertSame(403, $this->middleware()->handle($unbound, fn () => new Response())->getStatusCode());
    }

    private function middleware(): CollectionsTenantBindingMiddleware
    {
        return $this->container()->get(CollectionsTenantBindingMiddleware::class);
    }
}
