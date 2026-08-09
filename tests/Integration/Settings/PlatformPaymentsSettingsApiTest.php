<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Platform-payments-settings spec §2 (Task 6): `GET/PUT /v1/admin/settings/payments`, the
 * neutral controller that replaces thallo-commerce's retired
 * `Thallo\Commerce\Http\PaymentsSettingsController` (`/v1/admin/commerce/payments`).
 *
 * This suite is the full port + replacement of the retired
 * `tests/Integration/Commerce/CommercePaymentsEndpointTest.php` (deleted in the same change) plus
 * the new authority/route-retirement concerns Task 6 adds. Mapping from the old test, verbatim in
 * spirit:
 *
 *  - testGetReportsGatewayModeWithBooleanSecretStateOnly
 *      -> ported: {@see testGetReportsByteShapeParityWithBooleanSecretStateAndNoSecretMaterial()}.
 *  - testSecretWriteStoresCiphertextAndNeverEchoesThePlaintext
 *      -> ported: {@see testSecretWriteStoresCiphertextInTheSystemChannelAndNeverEchoesPlaintext()}.
 *  - testStoredSecretReachesPayviaDecryptedThroughTheSeam
 *      -> ported: {@see testPutRoundTripsThroughTheOverrideSeamToPayvia()}.
 *  - testDefaultGatewayAndEnabledRoundTrip -> ported: same method as above (extended).
 *  - testClearDeletesTheSecretRowAndEnvFallbackShowsThrough
 *      -> ported: {@see testAbsentNullAndBlankSecretHaveDistinctEffects()}.
 *  - testAbsentSecretFieldLeavesTheStoredValueUntouched -> ported: same method as above.
 *  - testValidationRejectsUnknownGatewaysAndMalformedFields
 *      -> ported + extended: {@see testValidationMatrixRejectsEveryMalformedShape()} (adds the
 *         ops-knob-field and non-string-secret cases the retiring controller silently accepted).
 *  - testOverrideWhitelistIgnoresOpsKnobsEvenWithARowPresent
 *      -> REPLACED, not ported: that test exercised `PlatformPayviaSettingsOverride`'s OWN
 *         whitelist, already exhaustively owned by
 *         `PlatformPayviaOverrideTest::testWhitelistRefusesUnknownKeysUnconfiguredGatewaysAndOpsKnobs`
 *         (Task 4). This suite's job is narrower and different: prove the CONTROLLER's own PUT
 *         validation rejects an ops-knob field name outright (422) — see
 *         {@see testValidationMatrixRejectsEveryMalformedShape()}'s `base_url` case — rather than
 *         silently ignoring it the way the retired controller did.
 *
 * New Task 6 concerns with no old-test analogue: the route/authority structural pin, the 401/403/200
 * authority matrix, the pre-marker/post-marker legacy-visibility GET behaviour (Task 4's
 * compatibility window), the system-channel-only write proof, and the retired commerce routes'
 * 404/405 truth table.
 */
final class PlatformPaymentsSettingsApiTest extends AppTestCase
{
    private const PATH = '/v1/admin/settings/payments';

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
            $db->table('tenant_memberships')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Structural pin: the route is registered under the exact name/middleware the brief pins.
    // ------------------------------------------------------------------

    public function testEveryRouteIsRegisteredWithItsNameAndTheExactGroupMiddleware(): void
    {
        $expectedMiddleware = ['auth', 'tenant_system', 'content_permission:tenancy.manage'];
        $expectedNames = [
            'GET' => 'thallo.settings.payments.show',
            'PUT' => 'thallo.settings.payments.update',
        ];

        foreach ($expectedNames as $method => $name) {
            $route = $this->findRoute($method, self::PATH);
            self::assertNotNull($route, "{$method} " . self::PATH . ' must be registered');
            self::assertSame($name, $route['name']);
            foreach ($expectedMiddleware as $middleware) {
                self::assertContains($middleware, (array) $route['middleware'], "{$method} " . self::PATH);
            }
        }
    }

    // ------------------------------------------------------------------
    // Authority matrix: anonymous 401, workspace billing.manage-only 403, platform operator 200.
    // ------------------------------------------------------------------

    public function testAnonymousRequestsAreRejectedWith401(): void
    {
        self::assertSame(401, $this->handle($this->jsonRequest('GET', self::PATH))->getStatusCode());
        self::assertSame(401, $this->handle($this->jsonRequest('PUT', self::PATH, []))->getStatusCode());
    }

    /**
     * `billing.manage` (workspace) and `tenancy.manage` (platform) are disjoint authorities
     * (platform-payments-settings spec + the subscriptions self-serve-checkout spec that
     * introduced `billing.manage`): a workspace owner/delegate holding only the workspace-scoped
     * billing capability must never reach platform-level payment gateway credentials. Built as a
     * REAL workspace actor (tenant + membership + a role delegated `billing.manage`, mirroring
     * `BillingManageCapabilityTest`'s own idiom) so this is a genuine "billing.manage-only actor",
     * not merely "an actor granted nothing".
     */
    public function testWorkspaceBillingManageOnlyActorIsRejectedWith403(): void
    {
        $tenantUuid = $this->seedTenant();
        $userUuid = $this->seedWorkspaceOwnerWithOnlyBillingManage($tenantUuid);
        $key = $this->issueApiKey($userUuid, ['billing.manage']);

        self::assertSame(403, $this->handle($this->apiKeyRequest('GET', self::PATH, $key))->getStatusCode());
        self::assertSame(
            403,
            $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, []))->getStatusCode(),
        );
    }

    public function testPlatformOperatorWithTenancyManageReceives200(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        self::assertSame(200, $this->handle($this->apiKeyRequest('GET', self::PATH, $key))->getStatusCode());
        self::assertSame(
            200,
            $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, []))->getStatusCode(),
        );
    }

    // ------------------------------------------------------------------
    // GET: byte-shape parity, boolean-only secret state, no secret material on the wire.
    // ------------------------------------------------------------------

    public function testGetReportsByteShapeParityWithBooleanSecretStateAndNoSecretMaterial(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $response = $this->handle($this->apiKeyRequest('GET', self::PATH, $key));
        self::assertSame(200, $response->getStatusCode());

        $data = $this->data($response);
        self::assertSame(['mode', 'default_gateway', 'gateways'], array_keys($data));
        self::assertSame('gateway', $data['mode']);
        self::assertSame(
            ['value', 'default', 'overridden'],
            array_keys($data['default_gateway']),
        );
        self::assertSame('paystack', $data['default_gateway']['value']);
        self::assertFalse($data['default_gateway']['overridden']);

        $byId = array_column($data['gateways'], null, 'id');
        self::assertSame(['paystack', 'stripe'], array_keys($byId), 'gateway list order must be preserved');

        foreach (['paystack', 'stripe'] as $id) {
            self::assertSame(
                ['id', 'enabled', 'secret_key', 'webhook_secret', 'default', 'webhook_url'],
                array_keys($byId[$id]),
            );
            self::assertSame(['value', 'default', 'overridden'], array_keys($byId[$id]['enabled']));
            self::assertSame(['set', 'source'], array_keys($byId[$id]['secret_key']));
            self::assertSame(['set', 'source'], array_keys($byId[$id]['webhook_secret']));
            self::assertIsBool($byId[$id]['secret_key']['set']);
            self::assertIsBool($byId[$id]['webhook_secret']['set']);
        }

        self::assertTrue($byId['paystack']['enabled']['value']);
        self::assertFalse($byId['stripe']['enabled']['value']);
        self::assertTrue($byId['paystack']['default']);
        self::assertFalse($byId['stripe']['default']);
        // No env keys in this install — everything honestly unset.
        self::assertSame(['set' => false, 'source' => null], $byId['paystack']['secret_key']);
        self::assertSame(['set' => false, 'source' => null], $byId['stripe']['webhook_secret']);

        // The copy-able dashboard URL: canonical origin + payvia's root-mounted webhook route.
        self::assertMatchesRegularExpression(
            '#^https?://[^/]+/webhooks/paystack$#',
            (string) $byId['paystack']['webhook_url'],
        );

        self::assertStringNotContainsString('sk_', (string) $response->getContent());
        self::assertStringNotContainsString('whsec_', (string) $response->getContent());
    }

    // ------------------------------------------------------------------
    // PUT round-trip through Task 4's override seam, all the way to Payvia's own reader.
    // ------------------------------------------------------------------

    public function testPutRoundTripsThroughTheOverrideSeamToPayvia(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $plaintext = 'sk_test_roundtrip456';

        $response = $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'default_gateway' => 'stripe',
            'gateways' => [
                'stripe' => ['enabled' => true, 'secret_key' => $plaintext],
                'paystack' => ['enabled' => false],
            ],
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString($plaintext, (string) $response->getContent());

        $data = $this->data($response);
        self::assertSame('stripe', $data['default_gateway']['value']);
        self::assertTrue($data['default_gateway']['overridden']);
        $byId = array_column($data['gateways'], null, 'id');
        self::assertTrue($byId['stripe']['enabled']['value']);
        self::assertTrue($byId['stripe']['default']);
        self::assertFalse($byId['paystack']['enabled']['value']);
        self::assertSame(['set' => true, 'source' => 'settings'], $byId['stripe']['secret_key']);

        // The full chain: PlatformPaymentsSettingsController -> PlatformPaymentSettingsStore
        // (system channel, encrypted) -> PlatformPayviaSettingsOverride (decrypts) ->
        // PayviaSettings::gatewayConfig — what GatewayManager and the drivers actually read.
        self::assertSame('stripe', PayviaSettings::defaultGateway($this->appContext()));
        $config = PayviaSettings::gatewayConfig($this->appContext(), 'stripe');
        self::assertSame($plaintext, $config['secret_key']);
        self::assertTrue($config['enabled']);
        self::assertFalse(PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['enabled']);
    }

    // ------------------------------------------------------------------
    // Secret PUT semantics: absent = unchanged, null/blank = forget, nonblank = store.
    // ------------------------------------------------------------------

    public function testAbsentNullAndBlankSecretHaveDistinctEffects(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        // 1) Nonblank -> stored.
        $stored = $this->data($this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['secret_key' => 'sk_test_keepme']],
        ])));
        $byId = array_column($stored['gateways'], null, 'id');
        self::assertSame(['set' => true, 'source' => 'settings'], $byId['paystack']['secret_key']);

        // 2) ABSENT field on a later write -> untouched (write-only contract).
        $absent = $this->data($this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['enabled' => true]],
        ])));
        $byId = array_column($absent['gateways'], null, 'id');
        self::assertSame(['set' => true, 'source' => 'settings'], $byId['paystack']['secret_key']);
        self::assertSame(
            'sk_test_keepme',
            PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['secret_key'],
        );

        // 3) BLANK string -> forget (row deleted; no env fallback in this install).
        $blanked = $this->data($this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['secret_key' => '   ']],
        ])));
        $byId = array_column($blanked['gateways'], null, 'id');
        self::assertSame(['set' => false, 'source' => null], $byId['paystack']['secret_key']);

        // Re-store, then clear with explicit NULL (equivalent effect to blank).
        $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['secret_key' => 'sk_test_gone']],
        ]));
        $nulled = $this->data($this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['secret_key' => null]],
        ])));
        $byId = array_column($nulled['gateways'], null, 'id');
        self::assertSame(['set' => false, 'source' => null], $byId['paystack']['secret_key']);
        self::assertNull(
            $this->connection()->table('thallo_system_flags')
                ->where(['key' => 'payvia.gateways.paystack.secret_key'])->first(),
        );
    }

    public function testSecretWriteStoresCiphertextInTheSystemChannelAndNeverEchoesPlaintext(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $plaintext = 'sk_test_veryserioussecret123';

        $response = $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'gateways' => ['paystack' => ['secret_key' => $plaintext]],
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString($plaintext, (string) $response->getContent());

        $row = $this->connection()->table('thallo_system_flags')
            ->where(['key' => 'payvia.gateways.paystack.secret_key'])->first();
        self::assertIsArray($row);
        self::assertNotSame($plaintext, $row['value']);
        self::assertTrue(
            $this->container()->get(EncryptionService::class)->isEncrypted((string) $row['value']),
        );
    }

    // ------------------------------------------------------------------
    // Writes land in the system channel ONLY — the legacy `settings` table is never touched.
    // ------------------------------------------------------------------

    public function testWritesLandInTheSystemChannelAndNeverTouchTheLegacySettingsTable(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $response = $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, [
            'default_gateway' => 'stripe',
            'gateways' => [
                'stripe' => ['enabled' => true, 'secret_key' => 'sk_channel_only', 'webhook_secret' => 'whsec_only'],
            ],
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertNotNull(
            $this->connection()->table('thallo_system_flags')
                ->where(['key' => 'payvia.default_gateway'])->first(),
        );
        self::assertNotNull(
            $this->connection()->table('thallo_system_flags')
                ->where(['key' => 'payvia.gateways.stripe.secret_key'])->first(),
        );
        self::assertNotNull(
            $this->connection()->table('thallo_system_flags')
                ->where(['key' => 'payvia.gateways.stripe.webhook_secret'])->first(),
        );

        self::assertSame(
            0,
            $this->connection()->table('settings')->whereLike('key', 'payvia.%')->count(),
            'no write through this controller may ever create a payvia.* row in the legacy settings table',
        );
    }

    // ------------------------------------------------------------------
    // 422 matrix.
    // ------------------------------------------------------------------

    public function testValidationMatrixRejectsEveryMalformedShape(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $cases = [
            'unknown top-level default_gateway' => ['default_gateway' => 'rogue'],
            'unknown gateway id' => ['gateways' => ['rogue' => ['enabled' => true]]],
            'non-bool enabled' => ['gateways' => ['paystack' => ['enabled' => 'yes']]],
            'overlength secret' => ['gateways' => ['paystack' => ['secret_key' => str_repeat('x', 513)]]],
            'ops knob field (base_url)' => ['gateways' => ['paystack' => ['base_url' => 'https://evil.example']]],
            'ops knob field (timeout)' => ['gateways' => ['paystack' => ['timeout' => 5]]],
            'non-string secret' => ['gateways' => ['paystack' => ['secret_key' => 12345]]],
            'gateways not an object' => ['gateways' => 'not-an-object'],
            'gateway fields not an object' => ['gateways' => ['paystack' => 'not-an-object']],
        ];

        foreach ($cases as $label => $body) {
            $response = $this->handle($this->apiKeyRequest('PUT', self::PATH, $key, $body));
            self::assertSame(422, $response->getStatusCode(), "{$label}: " . (string) $response->getContent());
        }
    }

    // ------------------------------------------------------------------
    // Migration-cutover GET behaviour: pre-marker legacy visibility, post-marker invisibility.
    // ------------------------------------------------------------------

    public function testPreMarkerGetReportsAnUnmarkedLegacyValueAsOverriddenAndPostMarkerIgnoresIt(): void
    {
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $legacyKey = 'payvia.gateways.stripe.secret_key';
        $legacyPlaintext = 'sk_legacy_pending_migration';

        // A row in the OLD, pre-retrofit `settings` table (schema: key, value, updated_at — no
        // tenant_uuid), exactly what a not-yet-migrated deployment carries forward. No platform
        // row exists yet, and the migration marker is absent (AppTestCase truncates
        // thallo_system_flags fresh every test).
        $encryption = $this->container()->get(EncryptionService::class);
        $this->connection()->table('settings')->insert([
            'key' => $legacyKey,
            'value' => $encryption->encrypt($legacyPlaintext, aad: $legacyKey),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertNull(
            $this->container()->get(SystemFlags::class)->get(PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY),
            'sanity: this install must be unmarked',
        );

        $before = $this->data($this->handle($this->apiKeyRequest('GET', self::PATH, $key)));
        $byIdBefore = array_column($before['gateways'], null, 'id');
        self::assertSame(
            ['set' => true, 'source' => 'settings'],
            $byIdBefore['stripe']['secret_key'],
            'GET must report the same effective source runtime uses during cutover: a served '
                . 'legacy value reports set:true, source:settings, exactly like a platform row would',
        );

        // Cut the installation over.
        $this->container()->get(SystemFlags::class)->put(PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY, '1');

        $after = $this->data($this->handle($this->apiKeyRequest('GET', self::PATH, $key)));
        $byIdAfter = array_column($after['gateways'], null, 'id');
        self::assertSame(
            ['set' => false, 'source' => null],
            $byIdAfter['stripe']['secret_key'],
            'once marked, the legacy row must become invisible — no env fallback in this install',
        );

        // The legacy row is untouched by any of this — pruning is a separate, explicit step.
        self::assertNotNull($this->connection()->table('settings')->where(['key' => $legacyKey])->first());
    }

    // ------------------------------------------------------------------
    // Retired commerce routes: GONE (404/405 via the commerce-pack inertness idiom).
    // ------------------------------------------------------------------

    public function testRetiredCommercePaymentsRoutesAreGone(): void
    {
        self::assertNull($this->findRoute('GET', '/v1/admin/commerce/payments'));
        self::assertNull($this->findRoute('PUT', '/v1/admin/commerce/payments'));

        // Mirrors InertnessTest's established idiom: a removed admin path with no registered verb
        // falls through to the render pack's public GET catch-all (404, "no such content"); a
        // non-GET verb has no matching route pattern at all (405).
        self::assertSame(
            404,
            $this->handle($this->jsonRequest('GET', '/v1/admin/commerce/payments'))->getStatusCode(),
        );
        self::assertSame(
            405,
            $this->handle($this->jsonRequest('PUT', '/v1/admin/commerce/payments', []))->getStatusCode(),
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

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

    /**
     * A real workspace owner holding a role delegated `billing.manage` in `$tenantUuid` — and
     * NOTHING else. Mirrors BillingManageCapabilityTest's own membership/delegation idiom.
     */
    private function seedWorkspaceOwnerWithOnlyBillingManage(string $tenantUuid): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'billing_only_' . substr($userUuid, 0, 8),
            'email' => $userUuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => $now,
        ]);

        return $userUuid;
    }

    private function issueApiKey(string $userUuid, array $scopes): string
    {
        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $userUuid,
            'name' => 'platform-payments-settings-api-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
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
    private function data(Response $response): array
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
            'username' => 'ppsa_' . substr($userUuid, 0, 8),
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

        return $this->issueApiKey($userUuid, $scopes);
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'ppsaapi_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'platform payments settings api test role',
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
