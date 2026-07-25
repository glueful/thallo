<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Handler;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\CommerceConfigurationException;
use Thallo\Commerce\Http\CommerceMetaController;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8 (Thallo admin-commerce-area plan, slice 3): `GET /v1/admin/commerce/meta` (design spec
 * §4.3) — the single settings/entitlement probe the Commerce admin SPA area shares across every
 * page and editor panel.
 *
 * Mirrors {@see AdminAuthorizationMatrixTest}'s established convention: the controller is
 * resolved directly from the container and driven with a hand-built `Request` carrying the
 * post-auth `'user'` attribute array (never `'auth.user'`) — the harness cannot mint bearer JWTs,
 * so probing the REAL {@see \App\Content\Authorization\PermissionRequirementAuthority} against
 * REAL seeded Aegis RBAC (rather than going through the full kernel) is the established
 * substitute. `commerce.view`/`commerce.manage` permission rows already exist (the pack's own
 * seed migration); this class only seeds roles/grants/users.
 *
 * The unknown-configured-currency and representative-exponent tests swap `commerce.currency` on
 * the shared app context via {@see \Glueful\Bootstrap\ApplicationContext::mergeConfigDefaults()}
 * and restore it in `tearDown()`. This works precisely because `commerce.currency` has NO
 * app/framework-level config FILE backing it (only the vendored Commerce extension's own
 * `mergeConfig()`-registered defaults) — `mergeConfigDefaults()` merges UNDER any file/env config
 * for that name, but there is no such file to lose to here, so the new default value is read back
 * unchanged (mirrors `Glueful\Extensions\Commerce\Tests\...\StockReportEndpointTest`'s identical
 * `$this->context->mergeConfigDefaults('commerce', [...])` idiom in the commerce repo itself). A
 * full second `bootAppWithConfigOverride()` boot was tried first and does NOT work for this
 * specific key: that helper deletes its temporary `config/testing/{file}.php` override in a
 * `finally` BEFORE returning the booted context, so only config read EAGERLY during boot (like
 * `ShopUrlGenerator`'s prefix validation) observes the override — `commerce.currency` is read
 * lazily, on the first `config()` call, which in a test always happens after that restore.
 */
final class CommerceMetaTest extends AppTestCase
{
    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        // Unconditional restore — cheap, and safe whether or not a given test method actually
        // overrode `commerce.currency` (see the class docblock for why this key alone tolerates
        // mergeConfigDefaults() as a same-process override).
        $this->appContext()->mergeConfigDefaults('commerce', ['currency' => 'USD']);

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

    // ------------------------------------------------------------------
    // Shape + effective permission flags (default USD/2-decimal config)
    // ------------------------------------------------------------------

    public function testViewOnlyUserSeesCanViewTrueAndCanManageFalse(): void
    {
        $user = $this->userGranted(['commerce.view']);

        $response = $this->controller()->meta($this->userRequest($user));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame(
            ['currency', 'currency_exponent', 'shop_index_url', 'low_stock_threshold', 'can_view', 'can_manage'],
            array_keys($data),
        );
        self::assertSame('USD', $data['currency']);
        self::assertSame(2, $data['currency_exponent']);
        self::assertSame($this->expectedOrigin() . '/shop', $data['shop_index_url']);
        self::assertSame(2, $data['low_stock_threshold']);
        self::assertTrue($data['can_view']);
        self::assertFalse($data['can_manage']);
    }

    public function testManageOnlyUserSeesBothFlagsTrueViaImplication(): void
    {
        $user = $this->userGranted(['commerce.manage']);

        $data = $this->data($this->controller()->meta($this->userRequest($user)));

        self::assertTrue($data['can_view'], 'commerce.manage must satisfy can_view via implication');
        self::assertTrue($data['can_manage']);
    }

    public function testUserWithNeitherPermissionSeesBothFlagsFalse(): void
    {
        $user = $this->userGranted([]);

        $data = $this->data($this->controller()->meta($this->userRequest($user)));

        self::assertFalse($data['can_view']);
        self::assertFalse($data['can_manage']);
    }

    // ------------------------------------------------------------------
    // Route wiring — structural (mirrors ProductLinkApiTest's identical convention)
    // ------------------------------------------------------------------

    public function testRouteIsRegisteredBehindAdminTenantBindingAndTheViewOrManageGate(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/commerce/meta');
        self::assertNotNull($route, 'GET /v1/admin/commerce/meta must be registered');

        $middleware = (array) ($route['middleware'] ?? []);
        self::assertContains('admin_tenant_binding', $middleware);
        self::assertContains('content_permission:commerce.view,commerce.manage', $middleware);

        $name = $route['name'] ?? null;
        self::assertIsString($name);
        self::assertStringStartsWith('thallo.commerce.admin.', $name);
    }

    public function testRouteIsAbsentWhenCapabilityDisabled(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $status = (new Application($disabledApp))->handle(
            Request::create('/v1/admin/commerce/meta', 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();

        self::assertSame(404, $status);

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // currency_exponent — Money::exponentFor() is the sole source, representative codes
    // ------------------------------------------------------------------

    public function testCurrencyExponentForAZeroDecimalCurrencyIsZero(): void
    {
        $data = $this->metaUnderCurrencyOverride('JPY');

        self::assertSame('JPY', $data['currency']);
        self::assertSame(0, $data['currency_exponent']);
    }

    public function testCurrencyExponentForAThreeDecimalCurrencyIsThree(): void
    {
        $data = $this->metaUnderCurrencyOverride('KWD');

        self::assertSame('KWD', $data['currency']);
        self::assertSame(3, $data['currency_exponent']);
    }

    /**
     * An unrecognised configured currency fails closed as a NAMED configuration error — never a
     * silent default-to-2 exponent — and, left uncaught, renders through the framework's generic
     * exception handler as a 500 with the stable `INTERNAL_SERVER_ERROR` error_code.
     */
    public function testUnknownConfiguredCurrencyFailsClosedWithANamedConfigurationError(): void
    {
        $this->appContext()->mergeConfigDefaults('commerce', ['currency' => 'ZZZ']);

        try {
            $this->controller()->meta(Request::create('/x', 'GET'));
            self::fail('Expected CommerceConfigurationException for an unknown currency code.');
        } catch (CommerceConfigurationException $e) {
            self::assertSame('commerce.currency', $e->configKey);
            self::assertStringContainsString('ZZZ', $e->getMessage());

            $response = (new Handler())->render($e);
            self::assertSame(500, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body);
            self::assertFalse($body['success']);
            self::assertSame('INTERNAL_SERVER_ERROR', $body['error']['error_code']);
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Overrides `commerce.currency` on the shared context (see class docblock), resolves the
     * controller, and returns the decoded `data` payload of an unauthenticated (no `'user'`
     * attribute) request — sufficient for the currency/exponent plumbing these tests exercise;
     * `can_view`/`can_manage` are proven separately above. `tearDown()` restores 'USD'.
     *
     * @return array<string,mixed>
     */
    private function metaUnderCurrencyOverride(string $currency): array
    {
        $this->appContext()->mergeConfigDefaults('commerce', ['currency' => $currency]);

        $response = $this->controller()->meta(Request::create('/x', 'GET'));
        self::assertSame(200, $response->getStatusCode());

        return $this->data($response);
    }

    private function controller(): CommerceMetaController
    {
        return $this->container()->get(CommerceMetaController::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function userRequest(string $userUuid): Request
    {
        $request = Request::create('/v1/admin/commerce/meta', 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid, 'roles' => [], 'claims' => ['scopes' => []]]);

        return $request;
    }

    /** @return array<string,mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return (array) $decoded['data'];
    }

    /**
     * Independently re-derives the expected single-store origin from `app.urls.base` — mirrors
     * {@see StorefrontPreviewUrlTest}/{@see ProductLinkApiTest}'s identical helper (each file
     * duplicates it rather than sharing, per this suite's own established convention).
     */
    private function expectedOrigin(): string
    {
        $base = (string) config($this->appContext(), 'app.urls.base', 'http://localhost');
        $parts = parse_url($base);
        self::assertIsArray($parts, 'app.urls.base must be an absolute URL');
        self::assertArrayHasKey('scheme', $parts);
        self::assertArrayHasKey('host', $parts);

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /** @param list<string> $grantedPermissionSlugs */
    private function userGranted(array $grantedPermissionSlugs): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;

        if ($grantedPermissionSlugs !== []) {
            $this->grantRole($userUuid, $grantedPermissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'cmmeta_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'commerce meta test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist (pack seed migration)");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
