<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\Console\MigratePlatformPaymentCredentialsCommand;
use App\Settings\PlatformPaymentSettingsStore;
use App\Settings\PlatformPayviaSettingsOverride;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\PlatformCredentialRecordingGateway;
use Glueful\Auth\UserIdentity;
use Glueful\Container\Container;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\ShopCheckoutController;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\SelfBillingController;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8 (platform-payments-settings plan, FINAL task) — the end-to-end regression + cutover
 * proof spec §3's "Regression" and "Cutover compatibility" rows demand at the CONSUMER level,
 * distinct from Task 4's own unit-level proof of {@see PlatformPayviaSettingsOverride} resolution
 * (`PlatformPayviaOverrideTest::testPlatformCredentialsWinUnderAHostileAmbientWorkspace` etc.) and
 * Task 5's migration-command matrix (`PlatformPaymentMigrationTest`). Those files already pin the
 * SEAM in isolation; this file drives the two real CONSUMERS — commerce storefront checkout
 * ({@see ShopCheckoutController::place()} through the real, container-bound
 * `PayviaPaymentCollector`) and subscriptions self-serve checkout
 * ({@see SelfBillingController::checkout()} through the real `SubscriptionCheckoutService`) — plus
 * webhook signature verification, end to end, so a future change that keeps the override correct
 * in isolation but wires a consumer to the wrong collaborator (exactly how the ORIGINAL bug this
 * whole program fixes — `SettingsStorePayviaOverride` bound per-pack, tenant-owned storage —
 * would have slipped past a seam-only test suite) cannot pass silently.
 *
 * Every test in this file is a PIN against the fully shipped Tasks 1-7 behavior (no production
 * code changes ship in this task), made non-vacuous with built-in "flip evidence" rather than a
 * separate before/after run: each hostile-workspace test captures the ACTUAL secret a recording
 * gateway double received and asserts it is the platform value AND explicitly NOT the hostile
 * value planted alongside it, so a regression that reintroduced tenant-scoped credential
 * resolution would fail these assertions for the right reason, not by omission. The cutover
 * sequence test additionally isolates the marker's OWN effect at the end of phase 3: it CLEARS
 * the just-migrated platform value (leaving the untouched legacy row physically in place) and
 * asserts the consumer falls all the way to env/config rather than resurrecting the legacy value
 * — every earlier phase-3 assertion would hold even with the marker gate deleted outright, since
 * the platform store already has a value and step 1 always wins first; only this final check
 * isolates step 2's own gate. (During development, the marker check in
 * {@see PlatformPayviaSettingsOverride::value()} was temporarily disabled to confirm this
 * assertion genuinely fails without the shipped gate, then restored — see the Task 8 report for
 * the observed failure.)
 *
 * Ambient "workspace context" for the two checkout consumers is Thallo's own single-store mode:
 * a persisted `tenancy.default_tenant_uuid` — the SAME flag
 * {@see \App\Tests\Integration\Commerce\ShopCheckoutTest} and
 * {@see \App\Tests\Integration\Subscriptions\SelfServeCheckoutTruthTableTest} already drive their
 * own checkouts through, and the flag {@see \Thallo\Tenancy\Tenant\SingleStoreTenant::resolve()}
 * (subscriptions) actually consults in this app. This file deliberately does NOT also set
 * `tenancy.schema_state=widened`: `settings` is itself a retrofit-OWNED table
 * (`Thallo\Tenancy\ThalloTenantTables::all()['settings']`, backfill 'rebuild'), and this
 * installation's REAL `settings` table has never been physically rebuilt with a `tenant_uuid`
 * column, so claiming 'widened' makes the tenancy write-barrier stamp every INSERT into
 * `settings` with a column that does not exist — a DB error, not a feature of this test. Commerce
 * therefore resolves the sentinel `''` tenant internally (mode (a), "clean install") for its own
 * catalog/cart/order partitioning here, which is irrelevant to the payments seam under test. The
 * REAL `settings` table in this installation is pre-retrofit (no `tenant_uuid` column — see
 * `database/migrations/013_CreateSettingsTable.php`), so a hostile row planted there directly
 * (mirroring `PlatformPayviaOverrideTest::testPlatformCredentialsWinUnderAHostileAmbientWorkspace`)
 * is exactly the row the temporary legacy compatibility reader would otherwise treat as the
 * unscoped candidate for ANY ambient workspace — the same shape a hostile per-workspace admin's
 * pre-this-program `SettingsStorePayviaOverride` write would have taken.
 *
 * {@see AppTestCase::setUp()} force-deletes every row in `settings` and `thallo_system_flags`
 * (and clears the `SettingsStore`/`SystemFlags` caches) before EVERY test method in the whole
 * suite, so each test below starts from a genuinely fresh, unmarked, credential-less install —
 * no manual cleanup of those two tables is needed. The Payvia `GatewayManager` driver map is an
 * in-memory container singleton that persists across tests, so this file resets it in setUp()
 * and tearDown() (mirroring `SelfServeCheckoutTruthTableTest::resetGatewayDriver()`) to guarantee
 * every other suite in the process still sees the real `PaystackGateway`.
 */
final class PlatformPaymentsRegressionTest extends AppTestCase
{
    private const GATEWAY = 'paystack';
    private const SECRET_KEY = 'payvia.gateways.paystack.secret_key';
    private const WEBHOOK_KEY = 'payvia.gateways.paystack.webhook_secret';
    private const DEFAULT_GATEWAY_KEY = 'payvia.default_gateway';
    private const ENABLED_KEY = 'payvia.gateways.paystack.enabled';

    /** @var list<string> */
    private array $tenantUuids = [];
    /** @var list<string> */
    private array $userUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGatewayDriver();
    }

    protected function tearDown(): void
    {
        $this->resetGatewayDriver();

        $db = $this->connection();
        if ($this->tenantUuids !== []) {
            $db->table('subscription_checkout_originations')->whereIn('tenant_uuid', $this->tenantUuids)
                ->forceDelete();
            $db->table('subscription_checkout_subject_guards')->whereIn('tenant_uuid', $this->tenantUuids)
                ->forceDelete();
            $db->table('subscriptions')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        if ($this->userUuids !== []) {
            $db->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        $this->tenantUuids = [];
        $this->userUuids = [];

        parent::tearDown();
    }

    /** The guaranteed non-capable baseline every test starts and ends on (mirrors Task 20/16). */
    private function resetGatewayDriver(): void
    {
        $this->container()->get(GatewayManager::class)->registerDriver(self::GATEWAY, PaystackGateway::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function encryption(): EncryptionService
    {
        return $this->container()->get(EncryptionService::class);
    }

    /** The REAL, container-bound platform store — the exact collaborator production writes reach. */
    private function platformStore(): PlatformPaymentSettingsStore
    {
        return $this->container()->get(PlatformPaymentSettingsStore::class);
    }

    // =========================================================================================
    // Row: commerce storefront checkout originates against PLATFORM credentials under a
    // hostile ambient workspace (spec §3 "Regression").
    // =========================================================================================

    /**
     * PIN + flip evidence. `PayviaPaymentCollector::initiate()` (the real, container-bound
     * `PaymentCollector`) is exercised through the real `ShopCheckoutController::place()`; the
     * recording double resolves its own secret via `PayviaSettings::gatewayConfig()` at call
     * time — precisely what a real Paystack/Stripe driver does internally — so the captured
     * value is what a live gateway call would actually have carried, not a value handed to it by
     * the test. A regression that let the hostile legacy row (or, before this program, a
     * workspace-scoped `SettingsStorePayviaOverride` row) win would flip both assertions below.
     */
    public function testCommerceCheckoutOriginatesAgainstPlatformCredentialsUnderHostileWorkspace(): void
    {
        $platformSecret = 'sk_platform_commerce_' . bin2hex(random_bytes(4));
        $this->platformStore()->putMany([
            self::DEFAULT_GATEWAY_KEY => self::GATEWAY,
            self::ENABLED_KEY => 'true',
            self::SECRET_KEY => $platformSecret,
        ]);
        $hostileSecret = 'sk_hostile_commerce_' . bin2hex(random_bytes(4));
        $this->seedHostileLegacyRow(self::DEFAULT_GATEWAY_KEY, 'stripe');
        $this->seedHostileLegacyRow(self::SECRET_KEY, $hostileSecret, secret: true);

        $result = $this->driveCommerceCheckout('hostile-commerce');

        self::assertContains(
            $result['response']->getStatusCode(),
            [200, 303],
            (string) $result['response']->getContent(),
        );
        self::assertSame(
            1,
            $result['double']->initiateCalls,
            'a real platform key is configured — the gateway must be reached',
        );
        self::assertSame($platformSecret, $result['double']->lastInitiateSecret);
        self::assertNotSame($hostileSecret, $result['double']->lastInitiateSecret);
    }

    // =========================================================================================
    // Row: subscriptions self-serve checkout originates against PLATFORM credentials under a
    // hostile ambient workspace (spec §3 "Regression").
    // =========================================================================================

    /** PIN + flip evidence — the subscriptions-side mirror of the commerce test above. */
    public function testSubscriptionCheckoutOriginatesAgainstPlatformCredentialsUnderHostileWorkspace(): void
    {
        $platformSecret = 'sk_platform_subscriptions_' . bin2hex(random_bytes(4));
        $this->platformStore()->putMany([
            self::DEFAULT_GATEWAY_KEY => self::GATEWAY,
            self::ENABLED_KEY => 'true',
            self::SECRET_KEY => $platformSecret,
        ]);
        $hostileSecret = 'sk_hostile_subscriptions_' . bin2hex(random_bytes(4));
        $this->seedHostileLegacyRow(self::DEFAULT_GATEWAY_KEY, 'stripe');
        $this->seedHostileLegacyRow(self::SECRET_KEY, $hostileSecret, secret: true);

        $result = $this->driveSubscriptionCheckout('hostile-subscriptions');

        self::assertSame(200, $result['response']->getStatusCode(), (string) $result['response']->getContent());
        self::assertSame(1, $result['double']->subscriptionCalls);
        self::assertSame($platformSecret, $result['double']->lastSubscriptionSecret);
        self::assertNotSame($hostileSecret, $result['double']->lastSubscriptionSecret);
    }

    // =========================================================================================
    // Row: webhook signature verification resolves the platform webhook secret under NO tenant
    // context at all (spec §3 "Regression" — the third consumer path, distinct from the two
    // checkout-initiation rows above).
    // =========================================================================================

    /**
     * PIN + flip evidence. Deliberately NEVER calls {@see self::seedWorkspaceForCheckout()} —
     * no `tenancy.default_tenant_uuid` is ever set in this test, so there is no ambient
     * workspace of any kind (the strictest form of "tenant-context independence": not merely a
     * DIFFERENT workspace, but none at all). Drives the REAL `PaystackGateway` (no double) via
     * `GatewayManager::webhookGateway()`, the exact call
     * {@see \Glueful\Extensions\Payvia\Services\WebhookService::ingest()} makes before accepting
     * a delivery.
     */
    public function testWebhookSignatureVerificationResolvesThePlatformWebhookSecretUnderNoTenantContext(): void
    {
        self::assertNull($this->flags()->get('tenancy.default_tenant_uuid'), 'sanity: no ambient workspace at all');

        $platformWebhookSecret = 'whsec_platform_' . bin2hex(random_bytes(4));
        $this->platformStore()->putMany([self::WEBHOOK_KEY => $platformWebhookSecret]);
        $hostileWebhookSecret = 'whsec_hostile_' . bin2hex(random_bytes(4));
        $this->seedHostileLegacyRow(self::WEBHOOK_KEY, $hostileWebhookSecret, secret: true);

        $body = (string) json_encode(['event' => 'charge.success', 'data' => ['status' => 'success']]);
        $platformSignature = hash_hmac('sha512', $body, $platformWebhookSecret);
        $hostileSignature = hash_hmac('sha512', $body, $hostileWebhookSecret);

        $gateway = $this->container()->get(GatewayManager::class)->webhookGateway(self::GATEWAY);

        self::assertTrue(
            $gateway->verifyWebhookSignature($body, ['x-paystack-signature' => $platformSignature]),
            'the platform webhook secret must verify under no ambient tenant context',
        );
        self::assertFalse(
            $gateway->verifyWebhookSignature($body, ['x-paystack-signature' => $hostileSignature]),
            'a hostile legacy row must never be able to sign an accepted webhook',
        );
    }

    // =========================================================================================
    // Row: the cutover sequence, end to end, across BOTH consumers (spec §3 "Cutover
    // compatibility" + this task's brief, driven through the REAL migration command rather than
    // three independently-seeded static states).
    // =========================================================================================

    /**
     * PIN, verified against a deliberately broken build during development (see class docblock):
     * with the marker check removed from {@see PlatformPayviaSettingsOverride::value()}, the final
     * phase-3 assertion failed exactly as expected (the legacy row was resurrected once the
     * platform value was cleared, instead of falling through to null) before the change was
     * reverted.
     *
     * Phase 1 — fresh install (no legacy row anywhere, no marker): both consumers read env/config,
     * observable as "the gateway is never actually reached" (no secret ⇒ commerce's
     * `PayviaPaymentCollector` degrades to manual BEFORE calling the driver at all; subscriptions'
     * engine has no equivalent pre-check, so it reaches the driver but the driver observes a null
     * secret — both are faithful, real consumer behavior for an unconfigured install).
     *
     * Phase 2 — a legacy-only row exists, pre-marker: the SAME legacy value serves BOTH
     * consumers (this task's brief, verbatim).
     *
     * Phase 3 — the REAL `thallo:payments:migrate-platform-credentials` command (resolved from
     * the container exactly as an operator's CLI invocation would) runs, writes the marker, and
     * the platform store now serves both consumers; the legacy row (left in place — no
     * `--prune-legacy`) is then proven truly IGNORED, not merely shadowed, by clearing the
     * migrated platform value and showing the legacy row is never resurrected.
     */
    public function testCutoverSequenceFreshInstallThenLegacyThenMigratedAcrossBothConsumers(): void
    {
        // ---- Phase 1: fresh install --------------------------------------------------------
        self::assertNull(
            $this->flags()->get(PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY),
            'sanity: a fresh install carries no migration marker',
        );
        self::assertSame(
            0,
            $this->connection()->table('settings')->where('key', 'like', 'payvia.%')->count(),
            'sanity: a fresh install carries no legacy payvia rows at all',
        );

        $phase1Commerce = $this->driveCommerceCheckout('cutover-phase1');
        self::assertContains($phase1Commerce['response']->getStatusCode(), [200, 303]);
        self::assertSame(
            0,
            $phase1Commerce['double']->initiateCalls,
            'a keyless gateway must never actually be reached by commerce checkout',
        );

        $phase1Subscription = $this->driveSubscriptionCheckout('cutover-phase1');
        self::assertSame(200, $phase1Subscription['response']->getStatusCode());
        self::assertSame(1, $phase1Subscription['double']->subscriptionCalls);
        self::assertNull(
            $phase1Subscription['double']->lastSubscriptionSecret,
            'env/config carries no secret on a fresh install',
        );

        // ---- Phase 2: legacy-only, pre-marker ------------------------------------------------
        $legacySecret = 'sk_legacy_cutover_' . bin2hex(random_bytes(4));
        $this->seedHostileLegacyRow(self::SECRET_KEY, $legacySecret, secret: true);

        $phase2Commerce = $this->driveCommerceCheckout('cutover-phase2');
        self::assertContains(
            $phase2Commerce['response']->getStatusCode(),
            [200, 303],
            (string) $phase2Commerce['response']->getContent(),
        );
        self::assertSame(1, $phase2Commerce['double']->initiateCalls);
        self::assertSame(
            $legacySecret,
            $phase2Commerce['double']->lastInitiateSecret,
            'the legacy value must serve commerce checkout before the marker is set',
        );

        $phase2Subscription = $this->driveSubscriptionCheckout('cutover-phase2');
        self::assertSame(200, $phase2Subscription['response']->getStatusCode());
        self::assertSame(
            $legacySecret,
            $phase2Subscription['double']->lastSubscriptionSecret,
            'the SAME legacy value must serve subscriptions checkout before the marker is set',
        );

        // ---- Phase 3: migrate -> marker -> platform serves, legacy ignored ------------------
        $tester = new CommandTester($this->container()->get(MigratePlatformPaymentCredentialsCommand::class));
        $status = $tester->execute([], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame('1', $this->flags()->get(PlatformPayviaSettingsOverride::MIGRATION_MARKER_KEY));

        $phase3Commerce = $this->driveCommerceCheckout('cutover-phase3');
        self::assertContains(
            $phase3Commerce['response']->getStatusCode(),
            [200, 303],
            (string) $phase3Commerce['response']->getContent(),
        );
        self::assertSame(
            $legacySecret,
            $phase3Commerce['double']->lastInitiateSecret,
            'the migrated value now comes from the platform store',
        );

        $phase3Subscription = $this->driveSubscriptionCheckout('cutover-phase3');
        self::assertSame($legacySecret, $phase3Subscription['double']->lastSubscriptionSecret);

        // Legacy is truly IGNORED post-marker -- not merely SHADOWED by a still-present platform
        // value (every assertion above would hold even if the marker gate were deleted entirely,
        // since the platform store already has a value and step 1 always wins first). Isolate the
        // marker's own effect: clear the just-migrated platform value (an operator clearing a
        // credential after cutover) while the legacy row is left physically in place (the default
        // run never pruned it -- no `--prune-legacy`). A marker-blind implementation would fall
        // through to the legacy reader and resurrect it; the shipped override must instead fall
        // all the way to null (config/env), because legacy is invisible once marked, REGARDLESS of
        // whether a platform value happens to exist for that specific key.
        self::assertNotNull(
            $this->connection()->table('settings')->where('key', '=', self::SECRET_KEY)->first(),
            'sanity: the default run must never delete the legacy row without --prune-legacy',
        );
        $this->platformStore()->forget(self::SECRET_KEY);

        $phase3AfterClear = $this->driveCommerceCheckout('cutover-phase3-cleared');
        self::assertSame(
            0,
            $phase3AfterClear['double']->initiateCalls,
            'a cleared, marked key must fall to manual collection (keyless), never resurrect the legacy row',
        );
        self::assertNull(
            $phase3AfterClear['double']->lastInitiateSecret,
            'the legacy row must stay invisible once marked, even though a platform value is now absent',
        );
    }

    // =========================================================================================
    // Harness
    // =========================================================================================

    private function seedHostileLegacyRow(string $key, string $value, bool $secret = false): void
    {
        $this->connection()->table('settings')->insert([
            'key' => $key,
            'value' => $secret ? $this->encryption()->encrypt($value, aad: $key) : $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A fresh, real, ambient "workspace" — single-store mode, matching how each consumer's own suite drives it. */
    private function seedWorkspaceForCheckout(string $seed): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $uuid;
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'ppr-' . $seed . '-' . strtolower(substr($uuid, 0, 6)),
            'name' => 'Platform Payments Regression ' . $seed,
            'status' => 'active',
        ]);
        // Deliberately does NOT set `tenancy.schema_state=widened`: `settings` is a
        // retrofit-OWNED table (`ThalloTenantTables::all()['settings']`, backfill 'rebuild'),
        // and this installation's REAL `settings` table has never actually been rebuilt with a
        // `tenant_uuid` column (`database/migrations/013_CreateSettingsTable.php` — matches the
        // pre-retrofit shape this file's hostile-row fixtures rely on). Claiming 'widened' without
        // that physical rebuild makes the tenancy retrofit write-barrier stamp every INSERT into
        // `settings` with a `tenant_uuid` column that does not exist, which is a DB error, not a
        // feature of this test. `ThalloCommerceTenantResolution` therefore resolves the sentinel
        // `''` tenant for commerce's OWN internal catalog/cart/order partitioning here (mode (a),
        // "clean install") — irrelevant to what this file actually tests, since the payments
        // credential seam under test never consults commerce's tenant resolution at all.
        // Subscriptions' `SingleStoreTenant::resolve()` needs only the flag below (no schema-state
        // dependency), which is the one thing that matters for "ambient workspace context" here.
        $this->flags()->put('tenancy.default_tenant_uuid', $uuid);

        return $uuid;
    }

    private function seedVerifiedUser(string $seed): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->userUuids[] = $uuid;
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'ppr_' . $seed . '_' . strtolower(substr($uuid, 0, 6)),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    private function registerRecordingDriver(PlatformCredentialRecordingGateway $double): void
    {
        $containerId = self::class . ':' . spl_object_id($double);
        $container = $this->container();
        self::assertInstanceOf(Container::class, $container);
        $container->load([$containerId => $double]);
        $container->get(GatewayManager::class)->registerDriver(self::GATEWAY, $containerId);
    }

    private function seedCommerceVariant(string $seed): string
    {
        $sku = 'ppr-sku-' . $seed . '-' . strtolower(bin2hex(random_bytes(3)));
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => strtolower($sku),
            'name' => 'Platform payments regression product',
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => $sku,
                'price' => 1500,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        return (string) $product['variants'][0]['uuid'];
    }

    /** @return array{response: Response, double: PlatformCredentialRecordingGateway} */
    private function driveCommerceCheckout(string $seed): array
    {
        $this->seedWorkspaceForCheckout($seed);

        $double = new PlatformCredentialRecordingGateway($this->appContext(), self::GATEWAY);
        $this->registerRecordingDriver($double);

        $context = $this->appContext();
        $carts = $this->container()->get(CartService::class);
        $created = $carts->create($context);
        $carts->addLine($context, $created['cart'], $this->seedCommerceVariant($seed), 1);

        $controller = $this->container()->get(ShopCheckoutController::class);
        $response = $controller->place(Request::create(
            '/_shop/checkout/place',
            'POST',
            [
                'idempotency_key' => 'ppr-commerce-' . $seed . '-' . bin2hex(random_bytes(4)),
                'email' => 'ppr-' . $seed . '@example.test',
                'addresses' => ['shipping' => ['country' => 'US']],
            ],
            [CartCookie::NAME => $created['token']],
        ));

        return ['response' => $response, 'double' => $double];
    }

    private function seedPurchasablePlan(string $seed): string
    {
        $planKey = 'ppr-plan-' . $seed . '-' . strtolower(bin2hex(random_bytes(3)));
        $this->container()->get(EngineGateway::class)->plans()->create([
            'plan_key' => $planKey,
            'display_name' => 'PPR Plan ' . $seed,
            'entitlements' => [],
            'status' => 'active',
            'provider_identifiers' => [self::GATEWAY => 'price_' . $planKey],
        ]);

        return $planKey;
    }

    /** @return array{response: Response, double: PlatformCredentialRecordingGateway} */
    private function driveSubscriptionCheckout(string $seed): array
    {
        $this->seedWorkspaceForCheckout($seed);
        $actor = $this->seedVerifiedUser($seed);
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
        $planKey = $this->seedPurchasablePlan($seed);

        $double = new PlatformCredentialRecordingGateway($this->appContext(), self::GATEWAY);
        $this->registerRecordingDriver($double);

        $controller = $this->container()->get(SelfBillingController::class);
        $request = Request::create(
            '/v1/admin/billing/checkout',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode(['plan_key' => $planKey]),
        );
        $request->headers->set('Idempotency-Key', 'ppr-sub-' . $seed . '-' . bin2hex(random_bytes(4)));
        $request->attributes->set('auth.user', new UserIdentity(uuid: $actor));

        $response = $controller->checkout($request);

        return ['response' => $response, 'double' => $double];
    }
}
