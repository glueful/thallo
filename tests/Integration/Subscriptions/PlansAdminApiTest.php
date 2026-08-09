<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\PlansController;

/**
 * Task 8 (Phase B): the platform Plans admin API -- `/v1/admin/subscriptions/plans*`. The first
 * HTTP surface in this pack, so this test also pins the route/auth/degraded-mode conventions
 * Task 9 (per-workspace subscriptions) reuses.
 *
 * Four things, per the task brief's Step 1:
 *  - happy-path CRUD driven through the REAL kernel (auth via a real `X-API-Key` header, mirroring
 *    {@see \App\Tests\Integration\Commerce\AdminAuthorizationMatrixTest}'s established convention
 *    for admin surfaces with no session-cookie harness), seeded via `POST .../import-config`.
 *  - a 403 for a `tenancy.manage`-less actor on EVERY registered route, looping the route table
 *    the structural pin below proves is live.
 *  - a 409 with `code: engine_disabled` when the gateway reports the engine unavailable -- driven
 *    directly against the controller with a hand-built EngineGateway wrapping a container that
 *    lacks `SubscriptionService::class` (the DISABLED trigger; mirrors
 *    {@see EngineGatewayTest::contextWithStubbedReadiness()}'s identical wrap-the-real-container
 *    idiom for SCHEMA_NOT_READY).
 *  - a 422 carrying the engine's OWN `plan_key is immutable.` message for a plan_key-change
 *    attempt.
 */
final class PlansAdminApiTest extends AppTestCase
{
    private const BASE = '/v1/admin/subscriptions';

    /** @var list<array{0:string,1:string}> [method, path] pairs this pack registers. */
    private const ROUTES = [
        ['GET', self::BASE . '/plans'],
        ['POST', self::BASE . '/plans'],
        ['PATCH', self::BASE . '/plans/{key}'],
        ['POST', self::BASE . '/plans/{key}/archive'],
        ['POST', self::BASE . '/plans/import-config'],
    ];

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM subscription_plans');
    }

    protected function tearDown(): void
    {
        $db = $this->connection();
        $db->getPDO()->exec('DELETE FROM subscription_plans');
        if ($this->userUuids !== []) {
            $db->table('api_keys')->whereIn('user_uuid', $this->userUuids)->forceDelete();
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
            $db->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Structural pin: every route is registered, named, and carries the
    // exact platform-authority middleware -- what the 403 loop below relies on.
    // ------------------------------------------------------------------

    public function testEveryRouteIsRegisteredWithItsNameAndTheExactGroupMiddleware(): void
    {
        $expectedMiddleware = ['auth', 'tenant_system', 'content_permission:tenancy.manage'];
        $expectedNames = [
            'GET:' . self::BASE . '/plans' => 'thallo.subscriptions.admin.plans.index',
            'POST:' . self::BASE . '/plans' => 'thallo.subscriptions.admin.plans.store',
            'PATCH:' . self::BASE . '/plans/{key}' => 'thallo.subscriptions.admin.plans.update',
            'POST:' . self::BASE . '/plans/{key}/archive' => 'thallo.subscriptions.admin.plans.archive',
            'POST:' . self::BASE . '/plans/import-config' => 'thallo.subscriptions.admin.plans.import_config',
        ];

        foreach (self::ROUTES as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            self::assertSame(PlansController::class, $route['handler'][0]);
            self::assertSame($expectedNames["{$method}:{$path}"], $route['name']);
            foreach ($expectedMiddleware as $middleware) {
                self::assertContains($middleware, (array) $route['middleware'], "{$method} {$path}");
            }
        }
    }

    // ------------------------------------------------------------------
    // 403 for a non-tenancy.manage actor, on EVERY registered route.
    // ------------------------------------------------------------------

    public function testEveryRouteRejectsANonTenancyManageActorWith403(): void
    {
        $key = $this->seedApiKeyUser([], []);

        foreach (self::ROUTES as [$method, $path]) {
            $requestPath = str_replace('{key}', 'irrelevant-plan-key', $path);
            $response = $this->handle($this->apiKeyRequest($method, $requestPath, $key));
            self::assertSame(
                403,
                $response->getStatusCode(),
                "{$method} {$requestPath} must reject a non-tenancy.manage actor",
            );
        }
    }

    // ------------------------------------------------------------------
    // Happy-path CRUD, seeded via import-config, through the real kernel.
    // ------------------------------------------------------------------

    public function testFullCrudLifecycleThroughTheRealKernel(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        // Seed the platform catalog from config/subscriptions.php's 'plans' (free, pro).
        $import = $this->handle($this->apiKeyRequest('POST', self::BASE . '/plans/import-config', $key, []));
        self::assertSame(200, $import->getStatusCode(), (string) $import->getContent());
        $importedKeys = array_column($this->data($import)['plans'], 'plan_key');
        self::assertContains('free', $importedKeys);
        self::assertContains('pro', $importedKeys);

        // list
        $list = $this->handle($this->apiKeyRequest('GET', self::BASE . '/plans', $key));
        self::assertSame(200, $list->getStatusCode(), (string) $list->getContent());
        $listedKeys = array_column($this->data($list)['plans'], 'plan_key');
        self::assertContains('free', $listedKeys);
        self::assertContains('pro', $listedKeys);

        // create
        $planKey = 'plans-api-test-' . strtolower(substr(Utils::generateNanoID(), 0, 8));
        $create = $this->handle($this->apiKeyRequest('POST', self::BASE . '/plans', $key, [
            'plan_key' => $planKey,
            'display_name' => 'Test Plan',
            'entitlements' => ['widgets.limit' => 5],
            'status' => 'draft',
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getContent());
        self::assertSame($planKey, $this->data($create)['plan_key']);
        self::assertSame('draft', $this->data($create)['status']);

        // update
        $update = $this->handle($this->apiKeyRequest('PATCH', self::BASE . "/plans/{$planKey}", $key, [
            'display_name' => 'Test Plan Updated',
        ]));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getContent());
        self::assertSame('Test Plan Updated', $this->data($update)['display_name']);

        // archive
        $archive = $this->handle($this->apiKeyRequest('POST', self::BASE . "/plans/{$planKey}/archive", $key));
        self::assertSame(200, $archive->getStatusCode(), (string) $archive->getContent());
        self::assertSame('archived', $this->data($archive)['status']);
    }

    // ------------------------------------------------------------------
    // 422: plan_key immutability, carrying the engine's own message.
    // ------------------------------------------------------------------

    public function testUpdateWithAChangedPlanKeyReturns422CarryingTheUpstreamMessage(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $planKey = 'plans-api-immutable-' . strtolower(substr(Utils::generateNanoID(), 0, 8));

        $create = $this->handle($this->apiKeyRequest('POST', self::BASE . '/plans', $key, [
            'plan_key' => $planKey,
            'display_name' => 'Immutable Test Plan',
            'entitlements' => [],
            'status' => 'draft',
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getContent());

        $response = $this->handle($this->apiKeyRequest('PATCH', self::BASE . "/plans/{$planKey}", $key, [
            'plan_key' => $planKey . '-renamed',
        ]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('plan_key is immutable', (string) $response->getContent());
    }

    // ------------------------------------------------------------------
    // 409: engine_disabled, driven directly against the controller with a stub gateway.
    // ------------------------------------------------------------------

    public function testEngineDisabledReturns409WithStructuredCodeOnEveryAction(): void
    {
        $controller = new PlansController($this->disabledGateway());
        $request = Request::create('/', 'GET');

        $cases = [
            'index' => fn () => $controller->index($request),
            'store' => fn () => $controller->store($request),
            'update' => fn () => $controller->update($request, 'whatever'),
            'archive' => fn () => $controller->archive($request, 'whatever'),
            'importConfig' => fn () => $controller->importConfig($request),
        ];

        foreach ($cases as $action => $call) {
            $response = $call();
            self::assertSame(409, $response->getStatusCode(), $action);
            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body);
            self::assertSame('engine_disabled', $body['error']['details']['code'] ?? null, $action);
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A hand-built {@see EngineGateway} wrapping the REAL container except for
     * `SubscriptionService::class`, which resolves as absent -- the DISABLED trigger per
     * `EngineGateway::engineState()`'s own docblock. Mirrors
     * {@see EngineGatewayTest::contextWithStubbedReadiness()}'s identical "wrap the real
     * container for one id" idiom, applied to the `has()` check instead of a stubbed readiness
     * service.
     */
    private function disabledGateway(): EngineGateway
    {
        $real = $this->appContext();
        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($real->getContainer()) implements ContainerInterface {
            public function __construct(private readonly ContainerInterface $real)
            {
            }

            public function get(string $id): mixed
            {
                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                if ($id === SubscriptionService::class) {
                    return false;
                }

                return $this->real->has($id);
            }
        });

        return new EngineGateway($context);
    }

    /** Real X-API-Key header, mirrors AdminAuthorizationMatrixTest::apiKeyRequest(). */
    private function apiKeyRequest(string $method, string $path, string $key, ?array $body = null): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer unused-clears-the-auth-middleware-bearer-gate',
                'HTTP_X_API_KEY' => $key,
            ],
            $body === null ? null : (string) json_encode($body),
        );
    }

    /** @return array<string,mixed> */
    private function data(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());

        return (array) $decoded['data'];
    }

    /** @param list<string> $grantedPermissionSlugs @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->userUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'plans_api_' . substr($userUuid, 0, 8),
            'email' => $userUuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($grantedPermissionSlugs !== []) {
            $this->grantRole($userUuid, $grantedPermissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $userUuid,
            'name' => 'plans-admin-api-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'plansapi_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'plans admin api test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
