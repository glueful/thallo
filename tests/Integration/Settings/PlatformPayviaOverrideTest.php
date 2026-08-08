<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\LegacyPlatformPaymentSettingsReader;
use App\Settings\LegacyPlatformPaymentSettingsRepository;
use App\Settings\PlatformPaymentSettingsStore;
use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 4 (platform-payments-settings spec §2 "Binding") — {@see PlatformPayviaSettingsOverride}:
 * the APP-OWNED implementation of payvia's host settings seam that replaces the retired
 * commerce-pack `SettingsStorePayviaOverride`.
 *
 * Resolution order, verbatim from the spec:
 *   1. {@see PlatformPaymentSettingsStore} (the unscoped system channel);
 *   2. ONLY while `payments.platform_credentials_migrated` is ABSENT from {@see SystemChannel},
 *      the temporary {@see LegacyPlatformPaymentSettingsReader};
 *   3. `null` — payvia's own config/env fallback applies.
 *
 * The editable whitelist is ported VERBATIM from the retired override: `payvia.default_gateway`
 * plus `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids present in the
 * `payvia.gateways` CONFIG map. Ops knobs (base_url, timeout, …) are never editable.
 *
 * Two properties this class must hold that the retired one did NOT:
 *  - ZERO capability gates — gateway credentials are platform infrastructure, so `thallo.commerce`
 *    / `thallo.subscriptions` being on, off, or the pack provider being absent entirely can never
 *    change what a payment gateway or a webhook signature check reads.
 *  - No ambient tenant context anywhere in the resolution: neither source is chosen through
 *    `SettingsStore`, `runAsTenant()`, or any current-tenant helper.
 */
final class PlatformPayviaOverrideTest extends AppTestCase
{
    private const MARKER = 'payments.platform_credentials_migrated';

    private string $legacyTable = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyTable = 'legacy_payvia_ovr_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->connection()->getSchemaBuilder()->createTable($this->legacyTable, function ($table) {
            $table->string('tenant_uuid', 191);
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->primary(['tenant_uuid', 'key']);
        });
    }

    protected function tearDown(): void
    {
        $this->connection()->getSchemaBuilder()->dropTableIfExists($this->legacyTable);
        parent::tearDown();
    }

    // ---- wiring ---------------------------------------------------------------------------

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function encryption(): EncryptionService
    {
        return $this->container()->get(EncryptionService::class);
    }

    private function platformStore(?SystemChannel $channel = null): PlatformPaymentSettingsStore
    {
        return new PlatformPaymentSettingsStore($channel ?? $this->flags(), $this->encryption());
    }

    /** A reader over the ISOLATED temp table — the shared `settings` table is never touched here. */
    private function legacyReader(?string $table = null): LegacyPlatformPaymentSettingsReader
    {
        return new LegacyPlatformPaymentSettingsReader(
            new LegacyPlatformPaymentSettingsRepository(
                $this->appContext(),
                $this->flags(),
                $table ?? $this->legacyTable,
            ),
            $this->encryption(),
        );
    }

    private function override(
        ?SystemChannel $channel = null,
        ?LegacyPlatformPaymentSettingsReader $legacy = null,
    ): PlatformPayviaSettingsOverride {
        $channel ??= $this->flags();

        return new PlatformPayviaSettingsOverride(
            $this->platformStore($channel),
            $channel,
            $legacy ?? $this->legacyReader(),
        );
    }

    private function insertLegacy(string $tenantUuid, string $key, string $value): void
    {
        $this->connection()->table($this->legacyTable)->insert([
            'tenant_uuid' => $tenantUuid,
            'key' => $key,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Seed a legacy row through the DEFAULT workspace pointer, so it is the resolved candidate. */
    private function seedLegacyCandidate(string $key, string $plaintext): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'legacy-default-ws');
        $stored = str_ends_with($key, 'secret_key') || str_ends_with($key, 'webhook_secret')
            ? $this->encryption()->encrypt($plaintext, aad: $key)
            : $plaintext;
        $this->insertLegacy('legacy-default-ws', $key, $stored);
    }

    private function markMigrated(): void
    {
        $this->flags()->put(self::MARKER, '1');
    }

    // ---- resolution order -------------------------------------------------------------------

    public function testPlatformValueWinsOverAPresentLegacyCandidate(): void
    {
        $this->platformStore()->putMany([
            'payvia.default_gateway' => 'stripe',
            'payvia.gateways.stripe.secret_key' => 'sk_platform_wins',
        ]);
        $this->seedLegacyCandidate('payvia.default_gateway', 'paystack');
        $this->seedLegacyCandidate('payvia.gateways.stripe.secret_key', 'sk_legacy_loses');

        $override = $this->override();

        self::assertSame('stripe', $override->value($this->appContext(), 'payvia.default_gateway'));
        self::assertSame(
            'sk_platform_wins',
            $override->value($this->appContext(), 'payvia.gateways.stripe.secret_key'),
            'a platform secret must be served decrypted, and must never lose to a legacy row',
        );
    }

    public function testMarkerAbsentServesTheLegacyCandidateWhenNoPlatformValueExists(): void
    {
        $this->seedLegacyCandidate('payvia.default_gateway', 'paystack');
        $this->seedLegacyCandidate('payvia.gateways.paystack.webhook_secret', 'whsec_legacy');

        $override = $this->override();

        self::assertNull($this->flags()->get(self::MARKER), 'sanity: this install is unmarked');
        self::assertSame('paystack', $override->value($this->appContext(), 'payvia.default_gateway'));
        self::assertSame(
            'whsec_legacy',
            $override->value($this->appContext(), 'payvia.gateways.paystack.webhook_secret'),
        );
    }

    public function testMarkerAbsentServesThePreRetrofitUnscopedLegacyRow(): void
    {
        // The OTHER schema era: `key, value, updated_at`, no tenant_uuid — the one unscoped row
        // is the candidate (Task 3 fixture shape).
        $preTable = 'legacy_payvia_pre_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $schema = $this->connection()->getSchemaBuilder();
        $schema->createTable($preTable, function ($table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
        });

        try {
            $key = 'payvia.gateways.paystack.secret_key';
            $this->connection()->table($preTable)->insert([
                'key' => $key,
                'value' => $this->encryption()->encrypt('sk_pre_retrofit', aad: $key),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->connection()->table($preTable)->insert([
                'key' => 'payvia.default_gateway',
                'value' => 'paystack',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $override = $this->override(legacy: $this->legacyReader($preTable));

            self::assertSame('sk_pre_retrofit', $override->value($this->appContext(), $key));
            self::assertSame('paystack', $override->value($this->appContext(), 'payvia.default_gateway'));
        } finally {
            $schema->dropTableIfExists($preTable);
        }
    }

    public function testMarkerPresentIgnoresLegacyRowsEntirely(): void
    {
        $this->seedLegacyCandidate('payvia.default_gateway', 'paystack');
        $this->seedLegacyCandidate('payvia.gateways.paystack.secret_key', 'sk_legacy_stale');
        $this->markMigrated();

        $override = $this->override();

        // A marked installation can NEVER regress to a legacy value, even though the rows are
        // still physically present (pruning is a separate, explicit migration step).
        self::assertNull($override->value($this->appContext(), 'payvia.default_gateway'));
        self::assertNull($override->value($this->appContext(), 'payvia.gateways.paystack.secret_key'));
        self::assertNotNull(
            $this->connection()->table($this->legacyTable)
                ->where(['tenant_uuid' => 'legacy-default-ws', 'key' => 'payvia.default_gateway'])->first(),
            'sanity: the legacy rows really are still there — they were ignored, not deleted',
        );
    }

    public function testMarkerPresentStillServesPlatformValues(): void
    {
        $this->platformStore()->putMany(['payvia.default_gateway' => 'stripe']);
        $this->seedLegacyCandidate('payvia.default_gateway', 'paystack');
        $this->markMigrated();

        self::assertSame('stripe', $this->override()->value($this->appContext(), 'payvia.default_gateway'));
    }

    public function testAbsentEverywhereResolvesToNullSoConfigAndEnvApply(): void
    {
        $override = $this->override();

        foreach (
            [
                'payvia.default_gateway',
                'payvia.gateways.paystack.enabled',
                'payvia.gateways.paystack.secret_key',
                'payvia.gateways.stripe.webhook_secret',
            ] as $key
        ) {
            self::assertNull($override->value($this->appContext(), $key), $key);
        }

        // …and payvia therefore reads its own config, unchanged.
        self::assertSame('paystack', PayviaSettings::defaultGateway($this->appContext()));
        self::assertSame(
            'https://api.paystack.co',
            PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['base_url'],
        );
    }

    public function testBlankStoredValuesAreTreatedAsNoOverride(): void
    {
        // Byte-identical to the retired commerce override: a blank row is "no value", not "".
        $this->platformStore()->putMany(['payvia.default_gateway' => '   ']);

        self::assertNull($this->override()->value($this->appContext(), 'payvia.default_gateway'));
    }

    // ---- whitelist (ported verbatim) --------------------------------------------------------

    public function testWhitelistRefusesUnknownKeysUnconfiguredGatewaysAndOpsKnobs(): void
    {
        $refused = [
            // not a payvia key at all
            'theme.mode',
            'payvia.features.store_raw_payload',
            // ops knobs — config/env only, never editable
            'payvia.gateways.paystack.base_url',
            'payvia.gateways.paystack.timeout',
            'payvia.gateways.paystack.driver',
            'payvia.gateways.stripe.webhook_tolerance',
            // a gateway id that is NOT in the `payvia.gateways` CONFIG map: an override may
            // reconfigure a configured gateway, never invent one
            'payvia.gateways.rogue.secret_key',
            'payvia.gateways.rogue.enabled',
            // structurally malformed
            'payvia.gateways.paystack',
            'payvia.gateways.paystack.secret_key.extra',
            'payvia.default_gateway.extra',
        ];

        // Rows exist in BOTH sources for every refused key — the refusal must be structural,
        // not an accident of there being nothing to serve.
        $pairs = [];
        foreach ($refused as $key) {
            $pairs[$key] = 'hostile-' . $key;
            $this->seedLegacyCandidate($key, 'hostile-legacy-' . $key);
        }
        $this->platformStore()->putMany($pairs);

        $override = $this->override();
        foreach ($refused as $key) {
            self::assertNull($override->value($this->appContext(), $key), "{$key} must never be editable");
        }
    }

    public function testWhitelistAcceptsExactlyTheFourEditableShapes(): void
    {
        $accepted = [
            'payvia.default_gateway' => 'stripe',
            'payvia.gateways.paystack.enabled' => 'false',
            'payvia.gateways.paystack.secret_key' => 'sk_accepted',
            'payvia.gateways.paystack.webhook_secret' => 'whsec_accepted',
            'payvia.gateways.stripe.enabled' => 'true',
            'payvia.gateways.stripe.secret_key' => 'sk_stripe_accepted',
            'payvia.gateways.stripe.webhook_secret' => 'whsec_stripe_accepted',
        ];
        $this->platformStore()->putMany($accepted);

        $override = $this->override();
        foreach ($accepted as $key => $expected) {
            self::assertSame($expected, $override->value($this->appContext(), $key), $key);
        }
    }

    public function testOpsKnobsStayConfigDrivenThroughPayviaItself(): void
    {
        $this->platformStore()->putMany([
            'payvia.gateways.paystack.base_url' => 'https://evil.example',
        ]);

        self::assertSame(
            'https://api.paystack.co',
            PayviaSettings::gatewayConfig($this->appContext(), 'paystack')['base_url'],
        );
    }

    // ---- null-never-throw --------------------------------------------------------------------

    public function testAThrowingSystemChannelResolvesToNullRatherThanPropagating(): void
    {
        $throwing = new class implements SystemChannel {
            public function get(string $key): ?string
            {
                throw new \RuntimeException('storage unavailable');
            }

            public function put(string $key, string $value): void
            {
            }

            public function forget(string $key): void
            {
            }
        };

        // A legacy candidate exists — but the marker read itself blew up, so the safe answer is
        // "no override" (config/env), NEVER a legacy value chosen on an unknown marker state.
        $this->seedLegacyCandidate('payvia.default_gateway', 'paystack');

        self::assertNull(
            $this->override(channel: $throwing)->value($this->appContext(), 'payvia.default_gateway'),
        );
    }

    public function testTamperedPlatformSecretResolvesToNullAndNeverRegressesOnAMarkedInstall(): void
    {
        $key = 'payvia.gateways.paystack.secret_key';
        $this->platformStore()->putMany([$key => 'sk_original']);
        $stored = (string) $this->flags()->get($key);
        $mid = intdiv(strlen($stored), 2);
        $this->flags()->put(
            $key,
            substr($stored, 0, $mid) . ($stored[$mid] === 'A' ? 'B' : 'A') . substr($stored, $mid + 1),
        );
        $this->seedLegacyCandidate($key, 'sk_legacy_stale');
        $this->markMigrated();

        self::assertNull($this->override()->value($this->appContext(), $key));
    }

    public function testUndecryptableLegacySecretResolvesToNull(): void
    {
        $key = 'payvia.gateways.paystack.secret_key';
        $this->flags()->put('tenancy.default_tenant_uuid', 'legacy-default-ws');
        $this->insertLegacy('legacy-default-ws', $key, 'not-a-ciphertext-at-all');

        self::assertNull($this->override()->value($this->appContext(), $key));
    }

    // ---- container binding + zero capability gates --------------------------------------------

    public function testTheContainerBindsTheAppOwnedOverride(): void
    {
        self::assertInstanceOf(
            PlatformPayviaSettingsOverride::class,
            $this->container()->get(PayviaSettingsOverride::class),
        );
    }

    /**
     * Capability INDEPENDENCE. The retired commerce override gated `value()` on
     * `thallo.commerce`; this one must not gate on anything — a disabled storefront capability
     * silently reverting live gateway credentials to config/env is exactly the failure mode this
     * task exists to remove.
     *
     * Four secondary boots (the suite's standard boot-override harness): commerce capability off,
     * subscriptions capability off, both off, and the commerce pack PROVIDER removed from
     * config/serviceproviders.php entirely. The platform rows live in the shared DB, so every boot
     * reads the same storage — only the wiring differs.
     */
    public function testResolutionIsByteIdenticalAcrossCapabilityAndProviderVariants(): void
    {
        $this->platformStore()->putMany([
            'payvia.default_gateway' => 'stripe',
            'payvia.gateways.stripe.enabled' => 'true',
            'payvia.gateways.stripe.secret_key' => 'sk_capability_independent',
            'payvia.gateways.stripe.webhook_secret' => 'whsec_capability_independent',
        ]);
        $keys = [
            'payvia.default_gateway',
            'payvia.gateways.stripe.enabled',
            'payvia.gateways.stripe.secret_key',
            'payvia.gateways.stripe.webhook_secret',
            'payvia.gateways.stripe.base_url',
        ];

        $baseline = $this->resolveAll($this->appContext(), $keys);
        self::assertSame(
            [
                'payvia.default_gateway' => 'stripe',
                'payvia.gateways.stripe.enabled' => 'true',
                'payvia.gateways.stripe.secret_key' => 'sk_capability_independent',
                'payvia.gateways.stripe.webhook_secret' => 'whsec_capability_independent',
                'payvia.gateways.stripe.base_url' => null,
            ],
            $baseline,
            'sanity: the default (everything enabled) boot resolves the platform values',
        );

        $root = dirname(__DIR__, 3);
        /** @var list<string> $providers */
        $providers = (array) (require $root . '/config/serviceproviders.php')['enabled'];
        $withoutCommercePack = array_values(array_filter(
            $providers,
            static fn (string $p): bool => $p !== 'Thallo\\Commerce\\CommerceIntegrationServiceProvider',
        ));

        $variants = [
            'thallo.commerce disabled' => ['thallo', ['capabilities' => ['thallo.commerce' => false]]],
            'thallo.subscriptions disabled' => ['thallo', ['capabilities' => ['thallo.subscriptions' => false]]],
            'both capabilities disabled' => ['thallo', ['capabilities' => [
                'thallo.commerce' => false,
                'thallo.subscriptions' => false,
            ]]],
            'commerce pack provider absent' => ['serviceproviders', ['enabled' => $withoutCommercePack]],
        ];

        foreach ($variants as $label => [$file, $config]) {
            try {
                $app = self::bootAppWithConfigOverride($file, $config);
                self::assertInstanceOf(
                    PlatformPayviaSettingsOverride::class,
                    $app->getContainer()->get(PayviaSettingsOverride::class),
                    "the app-owned override must still be bound with {$label}",
                );
                self::assertSame($baseline, $this->resolveAll($app, $keys), "resolution changed with {$label}");
            } finally {
                self::resetSharedRepositoryConnection();
                self::restoreSharedPermissionProvider();
            }
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string,?string>
     */
    private function resolveAll(ApplicationContext $app, array $keys): array
    {
        /** @var PayviaSettingsOverride $override */
        $override = $app->getContainer()->get(PayviaSettingsOverride::class);

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $override->value($app, $key);
        }

        return $out;
    }

    // ---- ambient tenant-context independence --------------------------------------------------

    /**
     * The property the whole task turns on: gateway credentials are INSTALLATION-level, so what a
     * checkout or a webhook signature check reads must not depend on which workspace happens to be
     * ambient. This drives a REAL {@see TenantContextRunner} (glueful/tenancy's always-on control
     * plane) against a REAL, seeded workspace, with hostile `payvia.*` rows planted in the actual
     * `settings` table — the exact storage the legacy compatibility path reads.
     *
     * Covered consumer paths, all through the same seam:
     *  - `PayviaSettings::gatewayConfig()` — what GatewayManager and every driver read;
     *  - Paystack's `verifyWebhookSignature()` — the precise call
     *    {@see \Glueful\Extensions\Payvia\Services\WebhookService::ingest()} makes before it will
     *    accept a delivery;
     *  - {@see SelfServeGatewayCapability} — the subscriptions self-serve checkout config read.
     */
    public function testPlatformCredentialsWinUnderAHostileAmbientWorkspace(): void
    {
        $this->platformStore()->putMany([
            'payvia.default_gateway' => 'paystack',
            'payvia.gateways.paystack.enabled' => 'true',
            'payvia.gateways.paystack.secret_key' => 'sk_platform_truth',
            'payvia.gateways.paystack.webhook_secret' => 'whsec_platform_truth',
        ]);
        // Hostile rows in the REAL legacy settings table (this install is pre-retrofit, so these
        // are exactly the rows the compatibility reader would otherwise resolve as candidates).
        foreach (
            [
                'payvia.default_gateway' => 'stripe',
                'payvia.gateways.paystack.secret_key' => 'sk_hostile_workspace',
                'payvia.gateways.paystack.webhook_secret' => 'whsec_hostile_workspace',
            ] as $key => $plaintext
        ) {
            $this->connection()->table('settings')->insert([
                'key' => $key,
                'value' => str_contains($key, 'secret')
                    ? $this->encryption()->encrypt($plaintext, aad: $key)
                    : $plaintext,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $body = (string) json_encode(['event' => 'charge.success', 'data' => ['status' => 'success']]);
        $platformSignature = hash_hmac('sha512', $body, 'whsec_platform_truth');
        $hostileSignature = hash_hmac('sha512', $body, 'whsec_hostile_workspace');

        $slug = 'payvia-hostile-ws-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $tenantUuid = $this->seedRealTenant($slug);

        try {
            $runner = $this->container()->get(TenantContextRunner::class);
            $context = $this->appContext();
            $gateway = $this->container()->get(GatewayManager::class)->webhookGateway('paystack');

            $probe = function () use ($context, $gateway, $body, $platformSignature, $hostileSignature): array {
                $config = PayviaSettings::gatewayConfig($context, 'paystack');

                return [
                    'default_gateway' => PayviaSettings::defaultGateway($context),
                    'secret_key' => $config['secret_key'] ?? null,
                    'webhook_secret' => $config['webhook_secret'] ?? null,
                    'platform_signature_accepted' => $gateway->verifyWebhookSignature(
                        $body,
                        ['x-paystack-signature' => $platformSignature],
                    ),
                    'hostile_signature_accepted' => $gateway->verifyWebhookSignature(
                        $body,
                        ['x-paystack-signature' => $hostileSignature],
                    ),
                    'self_serve_gateway' => (new SelfServeGatewayCapability($context))->evaluate()['gateway'],
                ];
            };

            $observed = $runner->runAsTenant($tenantUuid, $probe);
        } finally {
            $this->forgetRealTenant($slug);
        }

        self::assertSame('paystack', $observed['default_gateway']);
        self::assertSame('sk_platform_truth', $observed['secret_key']);
        self::assertSame('whsec_platform_truth', $observed['webhook_secret']);
        self::assertTrue(
            $observed['platform_signature_accepted'],
            'the platform webhook secret must verify a signature under a hostile ambient workspace',
        );
        self::assertFalse(
            $observed['hostile_signature_accepted'],
            'a hostile workspace settings row must never be able to sign an accepted webhook',
        );
        self::assertSame(
            'paystack',
            $observed['self_serve_gateway'],
            'the subscriptions self-serve checkout config read must see the platform gateway',
        );
    }

    public function testLegacyFallbackIsAlsoIndependentOfAmbientTenantContext(): void
    {
        $this->flags()->put('tenancy.default_tenant_uuid', 'legacy-default-ws');
        $key = 'payvia.gateways.paystack.webhook_secret';
        $this->insertLegacy('legacy-default-ws', $key, $this->encryption()->encrypt('whsec_default_ws', aad: $key));
        $this->insertLegacy('hostile-ws', $key, $this->encryption()->encrypt('whsec_hostile_ws', aad: $key));

        $override = $this->override();
        $direct = $override->value($this->appContext(), $key);

        $slug = 'payvia-legacy-ambient-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $tenantUuid = $this->seedRealTenant($slug);
        try {
            $runner = $this->container()->get(TenantContextRunner::class);
            $context = $this->appContext();
            $wrapped = $runner->runAsTenant(
                $tenantUuid,
                static fn (): ?string => $override->value($context, $key),
            );
        } finally {
            $this->forgetRealTenant($slug);
        }

        self::assertSame('whsec_default_ws', $direct);
        self::assertSame($direct, $wrapped, 'a real runAsTenant() must not change the resolved credential');
    }

    // ---- the commerce pack no longer knows this seam exists -----------------------------------

    public function testCommercePackContainsNoReferenceToPayviaSettingsOverride(): void
    {
        $pack = dirname(__DIR__, 3) . '/packages/thallo-commerce';
        self::assertDirectoryExists($pack, 'sanity: the commerce pack directory must exist');

        self::assertFileDoesNotExist(
            $pack . '/src/Settings/SettingsStorePayviaOverride.php',
            'the pack-owned payvia override must be deleted, not merely unbound',
        );

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pack, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'PayviaSettingsOverride')) {
                $hits[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $hits,
            'no file in packages/thallo-commerce may reference PayviaSettingsOverride any more',
        );
    }

    // ---- real-tenant fixtures ------------------------------------------------------------------

    private function seedRealTenant(string $slug): string
    {
        $uuid = Utils::generateNanoID(12);
        Tenant::create($this->appContext(), [
            'uuid' => $uuid,
            'slug' => $slug,
            'name' => $slug,
            'status' => 'active',
        ]);

        return $uuid;
    }

    private function forgetRealTenant(string $slug): void
    {
        $this->connection()->table('tenants')->where(['slug' => $slug])->forceDelete();
    }
}
