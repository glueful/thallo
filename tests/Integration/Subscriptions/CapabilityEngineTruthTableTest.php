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
use Thallo\Subscriptions\Http\EngineNativeRoutesDenied;

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
 *  - capability requested ON + engine provider disabled: since the owner-availability gate
 *    (schema program Task 6), glueful/subscriptions OWNS `thallo.subscriptions` -- with the
 *    engine off the capability is requested but unavailable, so EFFECTIVELY off: the boot gate
 *    never loads this pack's routes and the surface answers exactly like Row 1, while the
 *    registry names the package and the enable remedy. Driven via a REAL second boot with
 *    glueful/subscriptions' own provider filtered out of `config/extensions.php`'s enabled list
 *    (the `bootWithEngineProviderDisabled()` idiom {@see EngineGatewayTest} establishes).
 * Final-wave fix A extends all three rows with the ENGINE's own native `/subscriptions/plans*`
 * mounts ({@see self::ENGINE_NATIVE_ROUTES}): before that fix Row 1 claimed more than it proved --
 * "capability off ⇒ 404" held only for this pack's routes while glueful/subscriptions' unconditional
 * `loadRoutesFrom()` kept a second, ungated plan-administration API live in every row.
 *
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
        // Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): the workspace billing
        // API. Different prefix/middleware chain (`/v1/admin/billing`, `admin_tenant_binding`) but
        // the SAME `thallo.subscriptions` capability gate -- loaded from the SAME boot() call as
        // every route above, so it belongs in this same route-registration truth table.
        ['GET', self::BILLING_BASE . '/meta'],
        ['POST', self::BILLING_BASE . '/checkout'],
        // Task 17: the destructive billing routes, same group/gate as the two above.
        ['POST', self::BILLING_BASE . '/cancel'],
        ['POST', self::BILLING_BASE . '/checkout/abandon'],
    ];

    private const BILLING_BASE = '/v1/admin/billing';

    /**
     * The engine's OWN native plan-management mounts (vendor/glueful/subscriptions/routes.php),
     * enumerated verbatim. Final-wave fix A: `glueful/subscriptions`' provider loads these
     * unconditionally from its `boot()` behind nothing but `['auth', 'subscriptions_plans_manage']`
     * -- outside the `thallo.subscriptions` capability gate, outside `tenant_system` +
     * `content_permission:tenancy.manage`, and outside {@see EngineGateway}. Without
     * `EnginePreemptionServiceProvider::denyEngineNativePlanRoutes()`, Row 1's "capability off ⇒ 404" claim
     * covered only THIS pack's routes while a whole second plan-administration API stayed live, and
     * any actor holding `subscriptions.plans.manage` could edit the global catalog -- exactly what
     * spec §3 rejects. Every row below pins their posture: unreachable in all three -- guarded-404 in
     * the two rows where the engine provider is on (capability off and capability on alike, for
     * anonymous callers and platform operators alike), and simply never registered in the row where
     * the engine provider itself is off.
     *
     * @var list<array{0:string,1:string}> method + exact template path
     */
    private const ENGINE_NATIVE_ROUTES = [
        ['GET', '/subscriptions/plans'],
        ['POST', '/subscriptions/plans'],
        ['POST', '/subscriptions/plans/import-config'],
        ['GET', '/subscriptions/plans/{key}'],
        ['PATCH', '/subscriptions/plans/{key}'],
        ['POST', '/subscriptions/plans/{key}/archive'],
    ];

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];
    /** @var list<string> */
    private array $tenantUuids = [];

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
        if ($this->tenantUuids !== []) {
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->tenantUuids = [];
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

            // ...and so is the ENGINE's own native plan API -- unconditionally, since this pack's
            // pre-emption lives on a separate pre-extension-tier provider's boot()
            // (EnginePreemptionServiceProvider), outside the capability gate entirely.
            $this->assertEngineNativePlanRoutesAreAbsent($hit, 'while the capability is off');
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    // ==================================================================
    // Row 2: capability requested ON, engine provider disabled -- effectively OFF (spec B3).
    // ==================================================================

    /**
     * Since the owner-availability gate (schema program Task 6), `thallo.subscriptions` is OWNED
     * by glueful/subscriptions: with the engine provider disabled the capability is REQUESTED but
     * not AVAILABLE, so it is effectively off -- the boot gate never loads this pack's routes and
     * the whole surface answers exactly like Row 1. The old "shell visible, controllers degrade
     * through EngineGateway" posture now applies only to secondary integrations (Payvia);
     * EngineGatewayTest keeps the gateway-level coverage.
     */
    public function testEngineDisabledMakesTheCapabilityEffectivelyOffDespiteBeingRequested(): void
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

            $registry = $container->get(CapabilityRegistry::class);

            // Still registered and still REQUESTED -- the switchboard was never touched...
            $ids = array_map(static fn ($c): string => $c->id, $registry->all());
            self::assertContains('thallo.subscriptions', $ids);
            self::assertTrue(
                $registry->isRequestedEnabled('thallo.subscriptions'),
                'the switchboard still says on -- only availability changed',
            );

            // ...but the OWNER is disabled, so availability fails with the package + remedy...
            $availability = $registry->availability('thallo.subscriptions');
            self::assertFalse($availability->available);
            self::assertStringContainsString('glueful/subscriptions', (string) $availability->reason);
            self::assertSame('php glueful extensions:enable glueful/subscriptions', $availability->remedy);

            // ...and EFFECTIVE state is requested AND available => off, everywhere at once.
            self::assertFalse(
                $registry->isEnabled('thallo.subscriptions'),
                'engine disabled must make the capability effectively off',
            );

            // The boot gate therefore never loaded this pack's routes: the whole admin surface
            // answers exactly like Row 1 (Render catch-all 404 for GET, router 405 otherwise).
            $routes = $container->get(Router::class)->getAllRoutes();
            $hit = static fn (string $method, string $path): int => (new Application($disabledEngineApp))->handle(
                Request::create($path, $method, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            )->getStatusCode();
            foreach (self::ALL_ROUTES as [$method, $template]) {
                self::assertFalse(
                    $this->routeIsRegistered($routes, $method, $template),
                    "{$method} {$template} must not be registered while the owner engine is disabled",
                );
                $path = str_replace(['{key}', '{uuid}', '{entitlement}'], 'irrelevant', $template);
                $expected = $method === 'GET' ? 404 : 405;
                self::assertSame(
                    $expected,
                    $hit($method, $path),
                    "{$method} {$path} must {$expected} while the owner engine is disabled",
                );
            }

            // The engine's native plan API is equally absent: with its provider off, its own
            // boot() never loads routes.php, and this pack deliberately does not pre-empt a file
            // nobody is going to load (EnginePreemptionServiceProvider).
            foreach (self::ENGINE_NATIVE_ROUTES as [$method, $template]) {
                self::assertFalse(
                    $this->routeIsRegistered($routes, $method, $template),
                    "{$method} {$template} (engine-native) must not be registered at all here",
                );
                $path = str_replace('{key}', 'irrelevant', $template);
                $expected = $method === 'GET' ? 404 : 405;
                self::assertSame(
                    $expected,
                    $hit($method, $path),
                    "{$method} {$path} (engine-native) must {$expected} while the engine provider is disabled",
                );
            }
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

    /**
     * Row 3, the operational boot: the engine's native plan API is STILL absent -- for an anonymous
     * caller (404, not the 401 an `auth`-first pipeline would give, so it is indistinguishable from
     * an unregistered path) and for a real platform operator holding `tenancy.manage` alike. The
     * ONLY plan-administration surface in this app is `/v1/admin/subscriptions/plans`, which the test
     * above proves still works.
     */
    public function testEngineNativePlanRoutesAreAbsentOnTheOperationalBootForEveryActor(): void
    {
        $anonymous = fn (string $method, string $path): int => $this->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();
        $this->assertEngineNativePlanRoutesAreAbsent($anonymous, 'for an anonymous caller');

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $operator = fn (string $method, string $path): int => $this->handle(
            $this->apiKeyRequest($method, $path, $key),
        )->getStatusCode();
        $this->assertEngineNativePlanRoutesAreAbsent($operator, 'for a platform operator');
    }

    /**
     * Structural pin for the mechanism itself: the guard must be the FIRST middleware on every
     * native mount (ahead of the engine's own `auth`/`subscriptions_plans_manage`), and each mount
     * must exist exactly ONCE -- proving the pack pre-empted the engine's `loadRoutesFrom()` rather
     * than registering a second, shadowed copy alongside it.
     */
    public function testEngineNativePlanRoutesCarryTheDenyGuardFirstAndAreRegisteredExactlyOnce(): void
    {
        $routes = $this->container()->get(Router::class)->getAllRoutes();

        foreach (self::ENGINE_NATIVE_ROUTES as [$method, $path]) {
            $matches = array_values(array_filter(
                $routes,
                static fn (array $route): bool => strtoupper((string) $route['method']) === $method
                    && (string) $route['path'] === $path,
            ));

            self::assertCount(1, $matches, "{$method} {$path} must be registered exactly once");
            $middleware = array_values((array) $matches[0]['middleware']);
            self::assertSame(
                EngineNativeRoutesDenied::ALIAS,
                $middleware[0] ?? null,
                "{$method} {$path} must run the deny guard BEFORE anything else (got: "
                . implode(',', array_map('strval', $middleware)) . ')',
            );
            self::assertContains(
                'auth',
                $middleware,
                'sanity: this really is the engine mount, with its own middleware still behind ours',
            );
        }
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * Every engine-native plan mount answers 404 -- the framework's OWN unmatched-route status --
     * for every method, including the non-GET verbs that an unrelated catch-all would otherwise
     * answer 405 for.
     *
     * @param callable(string,string):int $hit
     */
    private function assertEngineNativePlanRoutesAreAbsent(callable $hit, string $why): void
    {
        foreach (self::ENGINE_NATIVE_ROUTES as [$method, $template]) {
            $path = str_replace('{key}', 'irrelevant', $template);
            self::assertSame(
                404,
                $hit($method, $path),
                "{$method} {$path} (engine-native) must be absent (404) {$why}",
            );
        }
    }

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
