<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Http\SelfServeSettingsController;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 15 (Phase C, workspace self-serve checkout plan, spec §5.1): the
 * `self_serve_checkout_enabled` operator kill switch --
 * `PUT /v1/admin/subscriptions/self-serve` ({@see SelfServeSettingsController}) plus its exposure
 * on `GET /meta` ({@see \Thallo\Subscriptions\Http\MetaController}).
 *
 * Mirrors `PlansAdminApiTest`/`WorkspaceBillingApiTest`'s established conventions (real-kernel
 * API-key auth, structural route pin, 403 posture). The one twist this task adds is the gateway
 * capability gate: this app's real default gateway is Paystack, which deliberately does NOT
 * implement `SubscriptionInitiationCapableGateway` (2.5.0 sandbox proof --
 * `PaystackGateway`'s own docblock) -- every test therefore starts and ends with the default
 * gateway's driver forced back to the real `PaystackGateway` class (a guaranteed non-capable
 * baseline regardless of what `payvia.default_gateway` happens to resolve to in this
 * environment), and the "capable" cases temporarily re-register that SAME gateway NAME onto the
 * already-container-resolvable `StripeGateway::class` (which DOES implement the capability) via
 * `GatewayManager::registerDriver()` -- never inventing a new driver class, and always restored.
 */
final class SelfServeSwitchTest extends AppTestCase
{
    private const BASE = '/v1/admin/subscriptions';
    private const FLAG_KEY = 'subscriptions.self_serve_checkout_enabled';

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];
    /** @var list<string> */
    private array $tenantUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFlag();
        $this->resetGatewayDriver();
    }

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
            $db->table('tenant_memberships')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->tenantUuids = [];
        $this->resetFlag();
        $this->resetGatewayDriver();
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    private function resetFlag(): void
    {
        $this->connection()->table('thallo_system_flags')->where(['key' => self::FLAG_KEY])->delete();
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    private function defaultGatewayName(): string
    {
        return PayviaSettings::defaultGateway($this->appContext());
    }

    /** The guaranteed non-capable baseline every test starts and ends on. */
    private function resetGatewayDriver(): void
    {
        $this->container()->get(GatewayManager::class)
            ->registerDriver($this->defaultGatewayName(), PaystackGateway::class);
    }

    private function makeGatewayCapable(): void
    {
        $this->container()->get(GatewayManager::class)
            ->registerDriver($this->defaultGatewayName(), StripeGateway::class);
    }

    // ------------------------------------------------------------------
    // Default false on a fresh/upgraded install
    // ------------------------------------------------------------------

    public function testDefaultsToFalseOnAFreshInstall(): void
    {
        self::assertNull($this->container()->get(SystemFlags::class)->get(self::FLAG_KEY));
        self::assertFalse($this->container()->get(SelfServeCheckoutSetting::class)->isEnabled());
    }

    public function testAMalformedStoredValueStillReadsAsFalse(): void
    {
        $this->connection()->table('thallo_system_flags')->insert([
            'key' => self::FLAG_KEY,
            'value' => 'yes',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->container()->get(SystemFlags::class)->clearCache();

        self::assertFalse($this->container()->get(SelfServeCheckoutSetting::class)->isEnabled());
    }

    // ------------------------------------------------------------------
    // Structural pin: registered inside the platform group, exact middleware.
    // ------------------------------------------------------------------

    public function testRouteIsRegisteredInsideThePlatformGroupWithItsExactMiddlewareAndName(): void
    {
        $route = $this->findRoute('PUT', self::BASE . '/self-serve');
        self::assertNotNull($route, 'PUT ' . self::BASE . '/self-serve must be registered');
        self::assertSame(SelfServeSettingsController::class, $route['handler'][0]);
        self::assertSame('thallo.subscriptions.admin.self_serve.update', $route['name']);
        foreach (['auth', 'tenant_system', 'content_permission:tenancy.manage'] as $middleware) {
            self::assertContains($middleware, (array) $route['middleware']);
        }
    }

    // ------------------------------------------------------------------
    // Platform authority: 403 for anything short of tenancy.manage.
    // ------------------------------------------------------------------

    public function testRejectsAnActorWithNoPermissionsWith403(): void
    {
        $key = $this->seedApiKeyUser([], []);
        $response = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => false]));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testRejectsAPlatformOperatorHoldingOnlyAccessAnyWith403(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.access_any'], ['tenancy.access_any']);
        $response = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => false]));
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Disjoint-authority pin (spec §1, mirroring `BillingManageCapabilityTest`'s own ruling): a
     * real workspace `owner` -- who genuinely holds `billing.manage` via the tenant role matrix,
     * with NO platform `tenancy.manage`/`tenancy.access_any` Aegis grant at all -- still gets 403
     * on this PLATFORM route. `PermissionRequirementAuthority::allows()` only ever consults the
     * tenant role matrix/`OperatorBypass` when `tenancy.tenant` request state is present; this
     * route's group is `tenant_system` (never `admin_tenant_binding`), so no tenant context is
     * ever established here and the check falls straight through to a literal, global
     * `PermissionAuthority::can(..., 'tenancy.manage', ...)` -- a workspace-scoped
     * `billing.manage` grant, however real, can never satisfy it.
     */
    public function testRejectsAWorkspaceOwnerHoldingOnlyBillingManageWith403(): void
    {
        $tenantUuid = $this->seedTenant();
        $key = $this->seedApiKeyUser([], []);
        $userUuid = $this->userUuids[count($this->userUuids) - 1];
        $this->membership($tenantUuid, $userUuid, 'owner');

        $response = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => false]));
        self::assertSame(403, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Invalid payload -> 422
    // ------------------------------------------------------------------

    public function testInvalidPayloadsReturn422(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $cases = [
            'missing enabled key' => [],
            'enabled as a string' => ['enabled' => 'true'],
            'enabled as an int' => ['enabled' => 1],
            'enabled as null' => ['enabled' => null],
        ];

        foreach ($cases as $label => $body) {
            $response = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, $body));
            self::assertSame(422, $response->getStatusCode(), $label);
        }
    }

    // ------------------------------------------------------------------
    // Enable: refused without a capable gateway, allowed with one.
    // ------------------------------------------------------------------

    public function testEnableIsRefusedWithoutACapableGateway(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $response = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => true]));

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('no_capable_gateway', $this->errorCode($response));
        self::assertFalse($this->container()->get(SelfServeCheckoutSetting::class)->isEnabled());
    }

    public function testEnableSucceedsWithACapableGatewayAndRoundTripsThroughSystemFlags(): void
    {
        $this->makeGatewayCapable();
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $enable = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => true]));

        self::assertSame(200, $enable->getStatusCode(), (string) $enable->getContent());
        self::assertTrue($this->data($enable)['self_serve_checkout_enabled']);
        self::assertSame('1', $this->container()->get(SystemFlags::class)->get(self::FLAG_KEY));

        $disable = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/self-serve', $key, ['enabled' => false]));

        self::assertSame(200, $disable->getStatusCode(), (string) $disable->getContent());
        self::assertFalse($this->data($disable)['self_serve_checkout_enabled']);
        self::assertSame('0', $this->container()->get(SystemFlags::class)->get(self::FLAG_KEY));
    }

    // ------------------------------------------------------------------
    // Disable: always succeeds, even with payvia absent from the container.
    // ------------------------------------------------------------------

    public function testDisableAlwaysSucceedsEvenWithPayviaAbsentFromTheContainer(): void
    {
        $this->makeGatewayCapable();
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
        $this->resetGatewayDriver();
        self::assertTrue($this->container()->get(SelfServeCheckoutSetting::class)->isEnabled());

        $controller = new SelfServeSettingsController(
            $this->container()->get(SelfServeCheckoutSetting::class),
            new SelfServeGatewayCapability($this->payviaAbsentContext()),
        );

        $response = $controller->update($this->jsonRequest('PUT', '/', ['enabled' => false]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($this->data($response)['self_serve_checkout_enabled']);
        self::assertSame('0', $this->container()->get(SystemFlags::class)->get(self::FLAG_KEY));
    }

    // ------------------------------------------------------------------
    // Meta exposure.
    // ------------------------------------------------------------------

    public function testMetaExposesTheSwitchAndGatewayCapabilityState(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $off = $this->handle($this->apiKeyRequest('GET', self::BASE . '/meta', $key));
        self::assertSame(200, $off->getStatusCode());
        $offData = $this->data($off);
        self::assertFalse($offData['self_serve_checkout_enabled']);
        self::assertFalse($offData['self_serve_gateway_capable']);
        self::assertSame('gateway_not_capable', $offData['self_serve_gateway_capable_reason']);

        $this->makeGatewayCapable();
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();

        $on = $this->handle($this->apiKeyRequest('GET', self::BASE . '/meta', $key));
        self::assertSame(200, $on->getStatusCode());
        $onData = $this->data($on);
        self::assertTrue($onData['self_serve_checkout_enabled']);
        self::assertTrue($onData['self_serve_gateway_capable']);
        self::assertNull($onData['self_serve_gateway_capable_reason']);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** A hand-built context wrapping the REAL container except `GatewayManager::class`, which
     * resolves as absent -- the same "wrap the real container for one id" idiom
     * `PlansAdminApiTest::disabledGateway()`/`WorkspaceBillingApiTest::disabledGateway()`
     * establish for `EngineGateway`. */
    private function payviaAbsentContext(): ApplicationContext
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
                if ($id === GatewayManager::class) {
                    return false;
                }

                return $this->real->has($id);
            }
        });

        return $context;
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

    private function errorCode(\Glueful\Http\Response $response): ?string
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());

        return $decoded['error']['details']['code'] ?? null;
    }

    /** @param list<string> $grantedPermissionSlugs @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->userUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'self_serve_' . substr($userUuid, 0, 8),
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
            'name' => 'self-serve-switch-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'selfserve_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'self-serve switch test role',
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

    /** A real tenant to hold a workspace `owner` membership -- mirrors
     * `BillingManageCapabilityTest::seedTenant()`. */
    private function seedTenant(): string
    {
        $tenantUuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $tenantUuid;
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => $tenantUuid,
            'name' => $tenantUuid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tenantUuid;
    }

    /** Mirrors `BillingManageCapabilityTest::membership()` -- `owner` grants `billing.manage`
     * through the tenant role matrix alone, no override reconciliation needed. */
    private function membership(string $tenantUuid, string $userUuid, string $role): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
