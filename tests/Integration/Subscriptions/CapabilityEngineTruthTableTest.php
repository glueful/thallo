<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Http\Controllers\CapabilityAdminController;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Helpers\Utils;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\MetaController;
use Thallo\Subscriptions\Http\PlansController;

/**
 * Task 12 (Phase B, final task): the capability/engine truth table (spec §7), composed
 * end-to-end rather than piecemeal -- every earlier task's test proves ONE corner of this
 * (EngineGatewayTest the gateway states directly, PlansAdminApiTest/WorkspaceBillingApiTest a
 * hand-wrapped-container 409, PackWiringTest the default-enabled capability) but nothing before
 * this task drives a REAL capability-off boot through this pack's own routes, or checks that the
 * capabilities admin surface hides it.
 *
 * Three rows:
 *  - capability OFF: `SubscriptionsIntegrationServiceProvider::boot()` never calls
 *    `loadRoutesFrom()`, so every `/v1/admin/subscriptions/*` route 404s (the route file itself
 *    never loaded -- not a 403/409 from a route that exists), and the capabilities admin surface
 *    omits `thallo.subscriptions` from its enabled list (though the pack still REGISTERS the
 *    capability -- disabling never un-registers it, only hides/gates it). Driven via a REAL
 *    second boot with `config/testing/thallo.php`'s `capabilities` override (mirrors
 *    `StorefrontInertnessTest`'s established `thallo.commerce` capability-off idiom).
 *  - capability ON + engine provider disabled: the routes ARE registered (the shell is visible --
 *    capability gate alone decides route registration) but every accessor the controllers resolve
 *    through {@see EngineGateway} throws, so `/meta` still reports 200 with `engine: engine_disabled`
 *    (never a 500) and an engine-backed action (`/plans` index) answers structured 409
 *    `{error: {details: {code: 'engine_disabled'}}}`. Driven via a REAL second boot with
 *    glueful/subscriptions' own provider filtered out of `config/extensions.php`'s enabled list --
 *    the exact `bootWithEngineProviderDisabled()` idiom {@see EngineGatewayTest} establishes
 *    (including its `array_replace_recursive` index-merge padding workaround), just resolving
 *    this pack's OWN controllers from that boot's container instead of hand-constructing a
 *    gateway.
 *  - both ON: the ordinary process-shared boot every other Subscriptions test runs against --
 *    `/meta` reports `ready` and `/plans` answers 200, through the real kernel with real
 *    `X-API-Key` auth (mirrors `PlansAdminApiTest`'s established convention).
 */
final class CapabilityEngineTruthTableTest extends AppTestCase
{
    private const BASE = '/v1/admin/subscriptions';

    /**
     * @var list<array{0:string,1:string}> every route this pack registers, method + the exact
     * TEMPLATE path (with `{key}`/`{uuid}`/`{entitlement}` placeholders, matching
     * `getAllRoutes()`'s own `path` field verbatim -- used for Row 2's registration check).
     */
    private const ALL_ROUTES = [
        ['GET', self::BASE . '/plans'],
        ['POST', self::BASE . '/plans'],
        ['PATCH', self::BASE . '/plans/{key}'],
        ['POST', self::BASE . '/plans/{key}/archive'],
        ['POST', self::BASE . '/plans/import-config'],
        ['GET', self::BASE . '/meta'],
        ['GET', self::BASE . '/workspaces'],
        ['GET', self::BASE . '/workspaces/{uuid}'],
        ['PUT', self::BASE . '/workspaces/{uuid}/plan'],
        ['POST', self::BASE . '/workspaces/{uuid}/cancel'],
        ['PUT', self::BASE . '/workspaces/{uuid}/overrides/{entitlement}'],
        ['DELETE', self::BASE . '/workspaces/{uuid}/overrides/{entitlement}'],
    ];

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        if ($this->userUuids !== []) {
            $db->table('api_keys')->whereIn('user_uuid', $this->userUuids)->forceDelete();
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
            $db->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ==================================================================
    // Row 1: capability OFF -- routes 404, capability hidden (not un-registered).
    // ==================================================================

    public function testCapabilityOffMeans404OnEveryAdminRouteAndHiddenFromTheCapabilitiesList(): void
    {
        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.subscriptions' => false],
        ]);

        try {
            $registry = $disabledApp->getContainer()->get(CapabilityRegistry::class);

            // The pack still REGISTERS the capability during boot() (unconditional) -- disabling
            // only gates visibility/routes, it never un-registers it.
            $ids = array_map(static fn ($c): string => $c->id, $registry->all());
            self::assertContains(
                'thallo.subscriptions',
                $ids,
                'the capability must still be registered while disabled',
            );
            self::assertFalse(
                $registry->isEnabled('thallo.subscriptions'),
                'the config override must actually disable the capability on this boot',
            );

            // The capabilities admin surface (the admin SPA's own module-visibility signal)
            // must omit it from the ENABLED list.
            $capabilitiesController = new CapabilityAdminController($registry);
            $body = json_decode((string) $capabilitiesController->index()->getContent(), true);
            self::assertIsArray($body);
            $enabledIds = array_column((array) $body['data']['capabilities'], 'id');
            self::assertNotContains(
                'thallo.subscriptions',
                $enabledIds,
                'a disabled capability must not appear in the enabled-capabilities list',
            );

            // Every registered route is absent -- the route FILE itself never loaded (boot()'s
            // `loadRoutesFrom()` call sits behind `$registry->isEnabled('thallo.subscriptions')`).
            // Mirrors StorefrontInertnessTest's established proof idiom exactly: this app still
            // carries Render's OWN unconditional `GET /{path}` catch-all, which regex-matches
            // ANY path string (including this pack's own) -- so a GET here reaches
            // `RenderController::page()`, finds no such builder page, and 404s from THERE; a
            // non-GET verb never matches the catch-all's single GET registration at all, so the
            // router itself answers 405 (path "exists" only via that unrelated catch-all, wrong
            // method) -- production-parity either way, and both prove this pack's OWN route is
            // absent (a registered route of ITS OWN would never fall through to the catch-all).
            $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
                Request::create($path, $method, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            )->getStatusCode();

            foreach (self::ALL_ROUTES as [$method, $template]) {
                $path = str_replace(['{key}', '{uuid}', '{entitlement}'], 'irrelevant', $template);
                $expected = $method === 'GET' ? 404 : 405;
                self::assertSame(
                    $expected,
                    $hit($method, $path),
                    "{$method} {$path} must {$expected} while the capability is off",
                );
            }
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    // ==================================================================
    // Row 2: capability ON, engine provider disabled -- shell visible, degraded honestly.
    // ==================================================================

    public function testCapabilityOnEngineDisabledMeansRoutesRespondWithMetaEngineDisabledAndActions409(): void
    {
        $disabledEngineApp = $this->bootWithEngineProviderDisabled();

        try {
            $container = $disabledEngineApp->getContainer();

            // Sanity: this boot really lacks the engine binding (mirrors
            // EngineGatewayTest::testDisabledWhenContainerLacksSubscriptionService).
            self::assertFalse(
                $container->has(SubscriptionService::class),
                'sanity: this boot really lacks the engine binding',
            );

            // The capability itself is untouched -- still ON (this row's whole point: the
            // capability gate and the engine gate are two independent seams).
            self::assertTrue(
                $container->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions'),
                'the capability must stay enabled while only the engine provider is disabled',
            );

            // The shell is visible: every route is still REGISTERED on this boot's router
            // (capability gate alone decides registration, never the engine's own state).
            $routes = $container->get(Router::class)->getAllRoutes();
            foreach (self::ALL_ROUTES as [$method, $path]) {
                self::assertTrue(
                    $this->routeIsRegistered($routes, $method, $path),
                    "{$method} {$path} must stay registered while only the engine is disabled",
                );
            }

            // /meta: 200 always, reporting engine_disabled -- never a 500.
            $meta = $container->get(MetaController::class);
            $metaResponse = $meta->show(Request::create('/', 'GET'));
            self::assertSame(200, $metaResponse->getStatusCode());
            $metaBody = json_decode((string) $metaResponse->getContent(), true);
            self::assertIsArray($metaBody);
            self::assertSame(EngineGateway::DISABLED, $metaBody['data']['engine']);

            // An engine-backed action: structured 409, code engine_disabled. PlansController's
            // index() resolves the gateway as its very first step (no tenancy/workspace
            // resolution ahead of it, unlike WorkspaceBillingController), so this is the cleanest
            // proof of the degraded-action row.
            $plans = $container->get(PlansController::class);
            $plansResponse = $plans->index(Request::create('/', 'GET'));
            self::assertSame(409, $plansResponse->getStatusCode());
            $plansBody = json_decode((string) $plansResponse->getContent(), true);
            self::assertIsArray($plansBody);
            self::assertSame('engine_disabled', $plansBody['error']['details']['code'] ?? null);
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    // ==================================================================
    // Row 3: both ON -- operational, through the real kernel with real auth.
    // ==================================================================

    public function testBothOnMeansOperationalThroughTheRealKernel(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions'),
            'sanity: the shared boot runs with the capability enabled by default',
        );

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $meta = $this->handle($this->apiKeyRequest('GET', self::BASE . '/meta', $key));
        self::assertSame(200, $meta->getStatusCode(), (string) $meta->getContent());
        self::assertSame(EngineGateway::READY, $this->data($meta)['engine']);

        $plans = $this->handle($this->apiKeyRequest('GET', self::BASE . '/plans', $key));
        self::assertSame(200, $plans->getStatusCode(), (string) $plans->getContent());
        self::assertArrayHasKey('plans', $this->data($plans));
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @param list<array<string,mixed>> $routes */
    private function routeIsRegistered(array $routes, string $method, string $path): bool
    {
        foreach ($routes as $route) {
            if (
                strtoupper((string) $route['method']) === strtoupper($method)
                && (string) $route['path'] === $path
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A REAL second boot with glueful/subscriptions' own provider filtered out of
     * config/extensions.php's `enabled` list -- verbatim the idiom
     * {@see EngineGatewayTest::bootWithEngineProviderDisabled()} establishes (including its
     * `array_replace_recursive` index-merge padding workaround; see that method's own docblock
     * for why the padding is needed).
     */
    private function bootWithEngineProviderDisabled(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $base = (array) require $root . '/config/extensions.php';
        $engineProvider = \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class;

        /** @var list<string> $baseEnabled */
        $baseEnabled = (array) $base['enabled'];
        $withoutEngine = array_values(array_filter(
            $baseEnabled,
            static fn (string $provider): bool => $provider !== $engineProvider,
        ));
        while (count($withoutEngine) < count($baseEnabled)) {
            $withoutEngine[] = $withoutEngine[0];
        }

        return self::bootAppWithConfigOverride('extensions', ['enabled' => $withoutEngine]);
    }

    /** Real X-API-Key header, mirrors PlansAdminApiTest::apiKeyRequest(). */
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
            'username' => 'truthtable_' . substr($userUuid, 0, 8),
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
            'name' => 'truth-table-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'truthtable_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'capability/engine truth table test role',
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
