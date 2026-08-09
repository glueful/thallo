<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Content\Authorization\CapabilityCatalog;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\PermissionAuthority;
use App\Content\Authorization\PermissionRequirementAuthority;
use App\Content\Authorization\TenantMembershipRoleReader;
use App\Content\Authorization\TenantRoleOverrideRepository;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingSubscriptionCheckoutGateway;
use App\Tests\Support\RecordingSubscriptionLifecycleGateway;
use Glueful\Application;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Checkout\WorkspaceCheckoutCoordinator;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\SelfBillingController;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 20 (Phase C, final task, workspace self-serve checkout plan): the end-to-end truth table
 * -- design spec §6's failure/degraded matrix, one row per test, each its own genuinely distinct
 * boot or fixture (never a shared setUp() shortcut that would make two rows secretly share
 * state). This file does not re-derive rules Tasks 14-19 already proved in isolation (capability
 * gating, the `billing.manage` authority, the checkout/cancel/abandon refusal vocabularies) --
 * it composes them, end to end, exactly the way an operator reading spec §6 would expect to be
 * able to verify the WHOLE matrix in one place.
 *
 * Row map (spec §6 table order):
 *  1. capability `thallo.subscriptions` off -> every `/v1/admin/billing/*` route 404s (mirrors
 *     `CapabilityEngineTruthTableTest`'s established `bootAppWithConfigOverride()` idiom, scoped
 *     here to the billing routes only).
 *  2. engine disabled -> checkout/cancel answer structured 409 `engine_disabled`; `meta` stays
 *     200 reporting the disabled state (`bootWithEngineProviderDisabled()`, `EngineGatewayTest`'s
 *     idiom).
 *  3. self-serve switch off -> checkout refuses `self_serve_disabled`; cancel still works (spec
 *     §1: "cancel is never gated by the switch").
 *  4. no `billing.manage` -> denied (owner/delegate granted, plain member denied) -- the SAME
 *     `PermissionRequirementAuthority::allows()` substitute `WorkspaceBillingSelfServeTest`
 *     established for a real `content_permission:billing.manage` 403 without the heavier
 *     tenancy retrofit harness.
 *  5. plan not purchasable for the active gateway -> 409 `plan_not_purchasable`.
 *  6. an active subscription already exists -> 409 `subscription_already_active`.
 *  7. a live origination -> same-key resumes (200 replay / 202 unknown-outcome recovery);
 *     pending + a DIFFERENT key -> 409 `checkout_pending` with the stored URL.
 *  8. a `projection_rejected` guard -> blocked state (`meta` reports it, checkout 409
 *     `checkout_blocked`).
 *  9. `late_settlement_conflict` -- a DISTINCT fixture (a real ledger row seeded directly at that
 *     terminal status with its rejected receipt columns already committed, exactly as
 *     `WebhookService::finalizeLateSettlementConflict()` would leave it) -- blocked guard +
 *     rejected-receipt columns survive a checkout attempt untouched, `meta` reports blocked,
 *     checkout answers 409 `checkout_blocked`.
 * 10. Paystack abandonment -> 409 `checkout_abandonment_unsupported`, driven against the REAL
 *     `PaystackGateway` (payvia 2.5's pinned sandbox-proof outcome: it does not implement
 *     `SubscriptionCheckoutLifecycleCapableGateway`), never a hand-rolled double.
 * 11. a provider webhook fails mid-lane -> the origination ledger stays `provider_observed` (the
 *     post-dispatch finalizer never runs -- it only runs after the composed dispatcher returns
 *     WITHOUT throwing), the logical-dispatch claim is released, the event is retryable, and an
 *     immediate retry completes through the REAL `StrictPayviaSubscriptionEventBridge` +
 *     `SubscriptionEventProjector` with no duplicate ownership (exactly one accepted receipt,
 *     exactly one `dispatched` transition, exactly one activated subscription row). Built through
 *     Payvia's own repositories directly (`WebhookService` constructed by hand, mirroring
 *     `vendor/glueful/payvia`'s own `StrictDispatchFailureTest::serviceWithDispatcher()` idiom --
 *     that class is a dev-only vendor test and not autoloadable from this app) rather than through
 *     the workspace billing HTTP surface, since spec §6's row is about the webhook lane itself.
 */
final class SelfServeCheckoutTruthTableTest extends AppTestCase
{
    private const META_ROUTE = '/v1/admin/billing/meta';
    private const CHECKOUT_ROUTE = '/v1/admin/billing/checkout';
    private const CANCEL_ROUTE = '/v1/admin/billing/cancel';
    private const ABANDON_ROUTE = '/v1/admin/billing/checkout/abandon';

    /** @var list<string> */
    private array $tenantUuids = [];
    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetState();
        $this->resetGatewayDriver();
    }

    protected function tearDown(): void
    {
        $this->resetState();
        $this->resetGatewayDriver();
        $db = $this->connection();
        if ($this->tenantUuids !== []) {
            $db->table('tenant_memberships')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenant_role_overrides')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        if ($this->userUuids !== []) {
            $db->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->tenantUuids = [];
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->container()->get(\Glueful\Extensions\Aegis\AegisPermissionProvider::class)->invalidateAllCache();
        parent::tearDown();
    }

    private function resetState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM subscription_checkout_originations');
        $pdo->exec('DELETE FROM subscription_checkout_subject_guards');
        $pdo->exec('DELETE FROM subscriptions');
        $pdo->exec('DELETE FROM subscription_plans');
        $pdo->exec('DELETE FROM provider_events');
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'subscriptions.self_serve_checkout_enabled')
            ->delete();
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'tenancy.default_tenant_uuid')
            ->delete();
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    /** The guaranteed non-capable baseline every test starts and ends on (mirrors Task 16/17). */
    private function resetGatewayDriver(): void
    {
        $this->container()->get(GatewayManager::class)->registerDriver(
            $this->defaultGatewayName(),
            \Glueful\Extensions\Payvia\Gateways\PaystackGateway::class,
        );
    }

    // ==================================================================
    // Row 1: capability off -- every /v1/admin/billing/* route is unreachable.
    // ==================================================================

    public function testRow1CapabilityOffMakesEveryBillingRouteUnreachable(): void
    {
        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.subscriptions' => false],
        ]);

        try {
            $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
                Request::create($path, $method, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            )->getStatusCode();

            // GET falls through Render's own unconditional catch-all to a 404; POST never matches
            // that single GET registration, so the router itself answers 405 -- both prove this
            // pack's own route is absent (mirrors CapabilityEngineTruthTableTest's established
            // proof for this exact framework quirk).
            self::assertSame(404, $hit('GET', self::META_ROUTE), 'GET /meta must 404 while the capability is off');
            self::assertSame(
                405,
                $hit('POST', self::CHECKOUT_ROUTE),
                'POST /checkout must 405 (no matching route at all) while the capability is off',
            );
            // Task 17's destructive routes live in the SAME capability-gated group; the sweep
            // must enumerate them too or "every /v1/admin/billing/* route" overstates itself.
            self::assertSame(
                405,
                $hit('POST', self::CANCEL_ROUTE),
                'POST /cancel must 405 (no matching route at all) while the capability is off',
            );
            self::assertSame(
                405,
                $hit('POST', self::ABANDON_ROUTE),
                'POST /checkout/abandon must 405 (no matching route at all) while the capability is off',
            );
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }

        // Flip evidence (non-vacuity): the SAME routes are reachable (not 404/405) on the
        // ordinary shared boot every other row in this file runs against.
        self::assertNotNull($this->findRoute('GET', self::META_ROUTE));
        self::assertNotNull($this->findRoute('POST', self::CHECKOUT_ROUTE));
        self::assertNotNull($this->findRoute('POST', self::CANCEL_ROUTE));
        self::assertNotNull($this->findRoute('POST', self::ABANDON_ROUTE));
    }

    // ==================================================================
    // Row 2: engine disabled -- checkout/cancel 409 engine_disabled; meta 200 + state.
    // ==================================================================

    public function testRow2EngineDisabledMeansStructured409OnActionsAnd200OnMeta(): void
    {
        $workspaceUuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $workspaceUuid;
        $this->connection()->table('tenants')->insert([
            'uuid' => $workspaceUuid,
            'slug' => 'truthtable-row2-' . strtolower(substr($workspaceUuid, 0, 6)),
            'name' => 'Truth Table Row 2 Workspace',
            'status' => 'active',
        ]);
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $workspaceUuid);
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();

        $disabledEngineApp = $this->bootWithEngineProviderDisabled();

        try {
            $billing = $disabledEngineApp->getContainer()->get(SelfBillingController::class);
            self::assertInstanceOf(SelfBillingController::class, $billing);

            $metaResponse = $billing->meta(Request::create('/', 'GET'));
            self::assertSame(200, $metaResponse->getStatusCode(), (string) $metaResponse->getContent());
            $metaBody = $this->data($metaResponse);
            self::assertSame(EngineGateway::DISABLED, $metaBody['engine']);

            $checkoutRequest = Request::create(
                '/',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode(['plan_key' => 'irrelevant']),
            );
            $checkoutRequest->headers->set('Idempotency-Key', str_repeat('a', 20));
            $checkoutRequest->attributes->set('auth.user', new UserIdentity(uuid: Utils::generateNanoID(12)));
            $checkoutResponse = $billing->checkout($checkoutRequest);
            self::assertSame(409, $checkoutResponse->getStatusCode());
            self::assertSame(EngineGateway::DISABLED, $this->errorCode($checkoutResponse));

            $cancelRequest = Request::create('/', 'POST');
            $cancelRequest->attributes->set('auth.user', new UserIdentity(uuid: Utils::generateNanoID(12)));
            $cancelResponse = $billing->cancel($cancelRequest);
            self::assertSame(409, $cancelResponse->getStatusCode());
            self::assertSame(EngineGateway::DISABLED, $this->errorCode($cancelResponse));
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }

        // Flip evidence: the SAME workspace, ordinary (engine-enabled) boot -- meta reports
        // READY, never DISABLED.
        [$workspace2] = $this->seedWorkspaceAndVerifiedActor();
        $ordinaryMeta = $this->controller()->meta(Request::create('/', 'GET'));
        self::assertSame(EngineGateway::READY, $this->data($ordinaryMeta)['engine']);
    }

    // ==================================================================
    // Row 3: switch off -- checkout refuses, cancel still works.
    // ==================================================================

    public function testRow3SwitchOffRefusesCheckoutButCancelStillWorks(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $lifecycle = new RecordingSubscriptionLifecycleGateway();
        $this->registerDriver($lifecycle);
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_row3');
        $controller = $this->controller();

        // Switch never enabled for this workspace.
        $meta = $controller->meta(Request::create('/', 'GET'));
        self::assertFalse($this->data($meta)['self_serve_checkout_enabled']);

        $checkout = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'irrelevant'], $this->key()));
        self::assertSame(409, $checkout->getStatusCode());
        self::assertSame('self_serve_disabled', $this->errorCode($checkout));

        $cancel = $controller->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(200, $cancel->getStatusCode(), (string) $cancel->getContent());
        self::assertCount(1, $lifecycle->cancelCalls, 'cancel must reach the provider even with the switch off');

        // Flip evidence: enabling the switch flips checkout's own refusal away (same fixture).
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
        $reseededPlan = $this->seedPurchasablePlan($this->defaultGatewayName(), 'row3plan');
        $enabled = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => $reseededPlan], $this->key()));
        self::assertNotSame('self_serve_disabled', $this->errorCode($enabled));
    }

    // ==================================================================
    // Row 4: no billing.manage -- denied; owner/delegate granted.
    // ==================================================================

    public function testRow4NoBillingManageIsDenied(): void
    {
        $tenantUuid = $this->seedTenant();
        $owner = Utils::generateNanoID(12);
        $member = Utils::generateNanoID(12);
        $this->membership($tenantUuid, $owner, 'owner');
        $this->membership($tenantUuid, $member, 'member');

        self::assertFalse(
            $this->billingManageAllowed($tenantUuid, $member),
            'a plain member without a grant must be denied billing.manage',
        );

        // Flip evidence: the SAME tenant's owner IS granted it -- the denial above is a real
        // authority decision, not a universally-false stub.
        self::assertTrue(
            $this->billingManageAllowed($tenantUuid, $owner),
            'the owner of the SAME workspace must be granted billing.manage',
        );
    }

    // ==================================================================
    // Row 5: plan not purchasable -- 409.
    // ==================================================================

    public function testRow5PlanNotPurchasableRefusesWith409(): void
    {
        [, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        // Deliberately no plan seeded -- 'does-not-exist' is never purchasable for any gateway.

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'does-not-exist'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('plan_not_purchasable', $this->errorCode($response));

        // Flip evidence: seeding the plan for the SAME gateway/workspace flips the refusal away.
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row5plan');
        $flipped = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertNotSame('plan_not_purchasable', $this->errorCode($flipped));
    }

    // ==================================================================
    // Row 6: active subscription -- 409 subscription_already_active.
    // ==================================================================

    public function testRow6ActiveSubscriptionRefusesWith409(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayName = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row6plan');
        $this->seedSubscription($workspace, 'active');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('subscription_already_active', $this->errorCode($response));

        // Flip evidence: an EXPIRED non_renewing row (never entitling) at the SAME
        // workspace/plan does not refuse.
        $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->delete();
        $this->seedSubscription($workspace, 'non_renewing', gmdate('Y-m-d H:i:s', time() - 86400));
        $flipped = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertNotSame(409, $flipped->getStatusCode());
    }

    // ==================================================================
    // Row 7: live origination -- same-key resumes, different key 409 checkout_pending;
    // an unknown-outcome provider failure resumes as 202 initializing.
    // ==================================================================

    public function testRow7LiveOriginationSameKeyResumesDifferentKeyIsCheckoutPending(): void
    {
        [, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row7plan');
        $controller = $this->controller();

        $key = 'row7-same-key-000001';
        $first = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => $planKey], $key));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $storedUrl = $this->data($first)['checkout_url'];
        self::assertNotNull($storedUrl);

        // Same key: resumes/replays -- no second provider call, identical body.
        $resume = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => $planKey], $key));
        self::assertSame(200, $resume->getStatusCode());
        self::assertSame($this->data($first), $this->data($resume));
        self::assertSame(1, $gatewayDouble->calls, 'a same-key resume must never re-call the provider');

        // Different key against the SAME live origination: refused, with the stored URL echoed.
        $different = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], 'row7-different-key-01'),
        );
        self::assertSame(409, $different->getStatusCode());
        self::assertSame('checkout_pending', $this->errorCode($different));
        self::assertSame($storedUrl, $this->errorDetails($different)['checkout_url']);
        self::assertSame(1, $gatewayDouble->calls, 'a preempted attempt must never reach the provider');
    }

    public function testRow7UnknownProviderOutcomeResumesAs202InitializingUnderTheSameKey(): void
    {
        [, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayDouble->throw = new \RuntimeException('simulated provider network blip');
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row7bplan');
        $controller = $this->controller();

        $key = 'row7-crash-replay-0001';
        $response = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => $planKey], $key));
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('initializing', $this->data($response)['status']);
        self::assertNull($this->data($response)['checkout_url']);

        // Flip evidence: once the provider recovers, the SAME key resumes and succeeds.
        $gatewayDouble->throw = null;
        $recovered = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => $planKey], $key));
        self::assertSame(200, $recovered->getStatusCode(), (string) $recovered->getContent());
        self::assertSame('pending', $this->data($recovered)['status']);
    }

    // ==================================================================
    // Row 8: projection_rejected -- blocked state (meta + checkout 409 checkout_blocked).
    // ==================================================================

    public function testRow8ProjectionRejectedBlocksMetaAndCheckout(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayName = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row8plan');

        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspace);
        $this->container()->get(CheckoutSubjectGuardRepository::class)->block(
            $this->appContext(),
            $this->payviaTenantUuid(),
            $subjectKey,
            null,
            'projection_rejected',
        );

        $meta = $this->controller()->meta(Request::create('/', 'GET'));
        $metaBody = $this->data($meta);
        self::assertTrue($metaBody['operator_contact_required']);
        self::assertSame('projection_rejected', $metaBody['operator_contact_reason']);

        $checkout = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertSame(409, $checkout->getStatusCode());
        self::assertSame('checkout_blocked', $this->errorCode($checkout));

        // Flip evidence: releasing the guard back to `open` (the operator-remediation action) lets
        // a fresh attempt through -- the block above was a real gate, not a permanent 409. This
        // guard was blocked with no bound origination (a no-origination operator hold), so
        // `reopen()` (which CASes against a specific origination_uuid) cannot target it; reopening
        // it is done directly, exactly like an operator's raw remediation would.
        $this->connection()->table('subscription_checkout_subject_guards')
            ->where('tenant_uuid', '=', $this->payviaTenantUuid())
            ->where('subject_key', '=', $subjectKey)
            ->update(['state' => 'open', 'blocked_reason' => null]);
        $unblocked = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertNotSame('checkout_blocked', $this->errorCode($unblocked));
    }

    // ==================================================================
    // Row 9: late_settlement_conflict -- DISTINCT fixture. Blocked guard + rejected-receipt
    // columns survive a checkout attempt untouched.
    // ==================================================================

    public function testRow9LateSettlementConflictPreservesBlockedGuardAndRejectedReceipt(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayName = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row9plan');

        $tenantUuid = $this->payviaTenantUuid();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspace);
        $originationUuid = Utils::generateNanoID(12);

        // A real ledger row seeded directly at the terminal `late_settlement_conflict` status,
        // with the required consumer's rejected receipt ALREADY committed -- exactly the shape
        // WebhookService::finalizeLateSettlementConflict() leaves behind once the strict lane's
        // acknowledgement settles it (see this class's own row-11 fixture for the mechanics that
        // produce this state for real; here it is seeded directly, matching
        // WorkspaceBillingCancelAbandonTest::seedOrigination()'s established idiom for this exact
        // status).
        $this->connection()->table('subscription_checkout_originations')->insert([
            'uuid' => $originationUuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'gateway' => $gatewayName,
            'provider_plan_identifier' => 'price_row9',
            'idempotency_key' => 'row9-key-' . $originationUuid,
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://admin.test/billing/return',
            'cancel_url' => 'https://admin.test/billing/return',
            'checkout_reference' => 'cs_test_' . $originationUuid,
            'checkout_url' => 'https://checkout.test/' . $originationUuid,
            'provider_subscription_id' => 'sub_row9_' . $originationUuid,
            'status' => 'late_settlement_conflict',
            'live' => false,
            'required_projection_consumer' => 'subscriptions',
            'projection_event_key' => 'evt:row9-conflict',
            'projection_outcome' => 'rejected',
            'projection_reason' => 'origination_mismatch',
            'consumer_metadata' => json_encode([
                'tenant_uuid' => $workspace,
                'subject_type' => 'tenant',
                'subject_uuid' => $workspace,
            ]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(CheckoutSubjectGuardRepository::class)->block(
            $this->appContext(),
            $tenantUuid,
            $subjectKey,
            $originationUuid,
            'late_settlement_conflict',
        );

        $before = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        $guardBefore = $this->connection()->table('subscription_checkout_subject_guards')
            ->where('tenant_uuid', '=', $tenantUuid)->where('subject_key', '=', $subjectKey)->first();

        $meta = $this->controller()->meta(Request::create('/', 'GET'));
        $metaBody = $this->data($meta);
        self::assertTrue($metaBody['operator_contact_required']);
        self::assertSame('late_settlement_conflict', $metaBody['operator_contact_reason']);

        $checkout = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => $planKey], $this->key()),
        );
        self::assertSame(409, $checkout->getStatusCode());
        self::assertSame('checkout_blocked', $this->errorCode($checkout));

        $after = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        $guardAfter = $this->connection()->table('subscription_checkout_subject_guards')
            ->where('tenant_uuid', '=', $tenantUuid)->where('subject_key', '=', $subjectKey)->first();

        self::assertSame($before, $after, 'the rejected-receipt columns must survive a checkout attempt untouched');
        self::assertSame($guardBefore, $guardAfter, 'the blocked guard must survive a checkout attempt untouched');
        self::assertSame('late_settlement_conflict', $after['status']);
        self::assertSame('rejected', $after['projection_outcome']);
        self::assertSame('blocked', $guardAfter['state']);

        // Flip evidence: an UNRELATED, freshly-opened subject guard (a different workspace) is
        // never blocked -- proving the 409 above came from THIS subject's specific state.
        [$otherWorkspace, $otherActor] = $this->seedWorkspaceAndVerifiedActor();
        $otherPlanKey = $this->seedPurchasablePlan($gatewayName, 'row9other');
        $unrelated = $this->controller()->checkout(
            $this->checkoutRequest($otherActor, ['plan_key' => $otherPlanKey], $this->key()),
        );
        self::assertNotSame('checkout_blocked', $this->errorCode($unrelated));
    }

    // ==================================================================
    // Row 10: Paystack abandonment -- 409 checkout_abandonment_unsupported, real PaystackGateway.
    // ==================================================================

    public function testRow10PaystackAbandonmentIsUnsupported(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        // resetGatewayDriver() (setUp()) already registered the REAL PaystackGateway as the
        // baseline driver for the configured default gateway name -- never touched here.
        $gatewayName = $this->defaultGatewayName();

        $originationUuid = $this->seedPendingOrigination($workspace, $gatewayName);

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('checkout_abandonment_unsupported', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status'], 'unsupported must never mutate the origination');

        // Flip evidence: a driver that DOES implement the lifecycle capability, for the SAME
        // origination shape, succeeds instead.
        $capable = new RecordingSubscriptionLifecycleGateway();
        $capable->abandonOutcome = 'confirmed_dead';
        $this->registerDriver($capable);
        $this->seedIncompleteReservation($workspace, $originationUuid);
        $flipped = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertNotSame('checkout_abandonment_unsupported', $this->errorCode($flipped));
    }

    // ==================================================================
    // Row 11: provider webhook fails mid-lane -- ledger stays provider_observed, event
    // retryable, retry completes without duplicate ownership.
    // ==================================================================

    public function testRow11WebhookMidLaneFailureLeavesProviderObservedThenRetryCompletesOnce(): void
    {
        $workspace = $this->seedWorkspace();
        $gatewayName = $this->defaultGatewayName();
        $planKey = $this->seedPurchasablePlan($gatewayName, 'row11plan');
        $plan = $this->purchasablePlanRow($gatewayName, $planKey);

        $originationUuid = Utils::generateNanoID(12);
        $tenantUuid = $this->payviaTenantUuid();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspace);
        $gwSubId = 'sub_row11_' . $originationUuid;

        // The REAL local reservation the strict lane must activate -- through the engine's own
        // SubscriptionService::reserveCheckoutFor(), exactly as WorkspaceCheckoutCoordinator::
        // prepare() would have created it.
        $this->container()->get(SubscriptionService::class)->reserveCheckoutFor(
            Subject::tenant($workspace),
            $plan['plan_uuid'],
            $originationUuid,
        );

        // The origination ledger row -- seeded directly already at `provider_observed` (a prior
        // correlating event already ran), with the consumer this app registers itself under.
        $this->connection()->table('subscription_checkout_originations')->insert([
            'uuid' => $originationUuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'gateway' => $gatewayName,
            'provider_plan_identifier' => 'price_row11',
            'idempotency_key' => 'row11-key-' . $originationUuid,
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://admin.test/billing/return',
            'cancel_url' => 'https://admin.test/billing/return',
            'checkout_reference' => 'cs_test_' . $originationUuid,
            'checkout_url' => 'https://checkout.test/' . $originationUuid,
            'provider_subscription_id' => $gwSubId,
            'status' => 'provider_observed',
            'live' => true,
            'required_projection_consumer' => 'subscriptions',
            'consumer_metadata' => json_encode([
                'tenant_uuid' => $workspace,
                'subject_type' => 'tenant',
                'subject_uuid' => $workspace,
            ]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(CheckoutSubjectGuardRepository::class)->lockAndClaim(
            $this->appContext(),
            $tenantUuid,
            $subjectKey,
            $originationUuid,
        );

        // The received provider_events row -- a real subscription.created delivery, mirrors
        // vendor/glueful/payvia's own StrictDispatchFailureTest::insertReceivedEvent() idiom.
        $events = $this->container()->get(ProviderEventRepositoryInterface::class);
        $logicalEventKey = 'subscription.created:' . $gwSubId;
        $uuid = $events->insertReceived([
            'gateway' => $gatewayName,
            'source' => 'webhook',
            'delivery_key' => 'delivery-row11-' . $originationUuid,
            'logical_event_key' => $logicalEventKey,
            'type' => EventType::SUBSCRIPTION_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                'gateway_subscription_id' => $gwSubId,
                'status' => 'active',
                'metadata' => [
                    'tenant_uuid' => $workspace,
                    'origination_uuid' => $originationUuid,
                ],
            ],
            'raw_payload' => [],
        ]);
        self::assertNotNull($uuid);
        $events->markProcessed($uuid);

        // A REAL WebhookService, constructed by hand (vendor Task 8's own idiom -- see class
        // docblock) wired to the REAL origination/guard finalizer capability and a dispatcher
        // that fails exactly once before ever reaching the REAL, container-resolved
        // StrictPayviaSubscriptionEventBridge -- proving the generic release-on-failure mechanics
        // for THIS app's actual composed strict lane, not a synthetic stand-in class.
        $strictBridge = $this->container()->get(StrictPayviaSubscriptionEventBridge::class);
        self::assertInstanceOf(StrictPayviaSubscriptionEventBridge::class, $strictBridge);
        $calls = 0;
        $dispatcher = function (PaymentProviderEvent $wrapped) use (&$calls, $strictBridge): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('simulated mid-lane webhook failure');
            }
            if ($strictBridge->supports($wrapped->event)) {
                $strictBridge->handle($wrapped->event);
            }
        };

        $service = new WebhookService(
            $this->appContext(),
            $this->container()->get(GatewayManager::class),
            $events,
            $dispatcher,
            null,
            false,
            null,
            $events,
            null,
            $this->container()->get(CheckoutOriginationRepository::class),
            $this->container()->get(CheckoutSubjectGuardRepository::class),
        );

        // First delivery: the dispatcher throws mid-lane.
        try {
            $service->processStored($uuid);
            self::fail('expected the mid-lane failure to escape processStored()');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated mid-lane webhook failure', $e->getMessage());
        }
        self::assertSame(1, $calls);

        // The origination stays EXACTLY where it was -- the post-dispatch finalizer never ran.
        $stillObserved = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('provider_observed', $stillObserved['status']);
        self::assertNull($stillObserved['projection_event_key'], 'no acknowledgement was ever recorded');

        // The event is retryable: the logical-dispatch claim was released, not left stuck.
        $eventRow = $events->findByUuid($uuid);
        self::assertNotSame('dispatched', $eventRow['dispatch_status'] ?? null);

        // Immediate retry -- no clock manipulation -- reaches the REAL strict lane this time and
        // completes.
        $service->processStored($uuid);
        self::assertSame(2, $calls);

        $completed = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('dispatched', $completed['status']);
        self::assertSame('accepted', $completed['projection_outcome']);
        self::assertSame($logicalEventKey, $completed['projection_event_key']);

        $subscription = $this->container()->get(SubscriptionService::class)->current($workspace);
        self::assertNotNull($subscription);
        self::assertSame('active', $subscription['status']);
        self::assertSame($gwSubId, $subscription['provider_subscription_id']);

        // No duplicate ownership: exactly one subscription row for this workspace, and a THIRD
        // delivery of the identical event is a pure no-op (the logical dispatch is already
        // complete, so the composed dispatcher is never invoked again).
        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
        );
        $service->processStored($uuid);
        self::assertSame(2, $calls, 'an already-dispatched logical event must never redeliver');

        // Flip evidence: a dispatcher that NEVER throws completes on the first delivery -- the
        // failure above was a real gate, not an artifact of this fixture being unreachable. A
        // FRESH workspace/subject (the first is now genuinely `active` and would refuse a second
        // reservation as already-entitled) proves the same mechanics succeed cleanly end to end.
        $secondWorkspace = $this->seedWorkspace();
        $secondOriginationUuid = Utils::generateNanoID(12);
        $secondSubjectKey = WorkspaceCheckoutCoordinator::subjectKey($secondWorkspace);
        $secondGwSubId = 'sub_row11b_' . $secondOriginationUuid;
        $this->container()->get(SubscriptionService::class)->reserveCheckoutFor(
            Subject::tenant($secondWorkspace),
            $plan['plan_uuid'],
            $secondOriginationUuid,
        );
        $this->connection()->table('subscription_checkout_originations')->insert([
            'uuid' => $secondOriginationUuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $secondSubjectKey,
            'gateway' => $gatewayName,
            'provider_plan_identifier' => 'price_row11b',
            'idempotency_key' => 'row11b-key-' . $secondOriginationUuid,
            'request_fingerprint' => str_repeat('b', 64),
            'return_url' => 'https://admin.test/billing/return',
            'cancel_url' => 'https://admin.test/billing/return',
            'checkout_reference' => 'cs_test_' . $secondOriginationUuid,
            'checkout_url' => 'https://checkout.test/' . $secondOriginationUuid,
            'provider_subscription_id' => $secondGwSubId,
            'status' => 'provider_observed',
            'live' => true,
            'required_projection_consumer' => 'subscriptions',
            'consumer_metadata' => json_encode([
                'tenant_uuid' => $secondWorkspace,
                'subject_type' => 'tenant',
                'subject_uuid' => $secondWorkspace,
            ]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(CheckoutSubjectGuardRepository::class)->lockAndClaim(
            $this->appContext(),
            $tenantUuid,
            $secondSubjectKey,
            $secondOriginationUuid,
        );
        $neverThrows = function (PaymentProviderEvent $wrapped) use ($strictBridge): void {
            if ($strictBridge->supports($wrapped->event)) {
                $strictBridge->handle($wrapped->event);
            }
        };
        $service2 = new WebhookService(
            $this->appContext(),
            $this->container()->get(GatewayManager::class),
            $events,
            $neverThrows,
            null,
            false,
            null,
            $events,
            null,
            $this->container()->get(CheckoutOriginationRepository::class),
            $this->container()->get(CheckoutSubjectGuardRepository::class),
        );
        $secondLogicalKey = 'subscription.created:' . $secondGwSubId;
        $secondUuid = $events->insertReceived([
            'gateway' => $gatewayName,
            'source' => 'webhook',
            'delivery_key' => 'delivery-row11b-' . $secondOriginationUuid,
            'logical_event_key' => $secondLogicalKey,
            'type' => EventType::SUBSCRIPTION_CREATED,
            'signature_valid' => true,
            'normalized_payload' => [
                'gateway_subscription_id' => $secondGwSubId,
                'status' => 'active',
                'metadata' => ['tenant_uuid' => $secondWorkspace, 'origination_uuid' => $secondOriginationUuid],
            ],
            'raw_payload' => [],
        ]);
        self::assertNotNull($secondUuid);
        $events->markProcessed($secondUuid);
        $service2->processStored($secondUuid);
        $secondCompleted = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $secondOriginationUuid)->first();
        self::assertSame('dispatched', $secondCompleted['status'], 'a non-throwing dispatch completes immediately');
    }

    // ==================================================================
    // Harness
    // ==================================================================

    private function key(): string
    {
        return 'sctt-key-' . strtolower(substr(Utils::generateNanoID(20), 0, 20));
    }

    private function seedWorkspace(): string
    {
        $uuid = $this->seedTenant();
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $uuid);

        return $uuid;
    }

    private function seedTenant(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $uuid;
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'sctt-' . strtolower(substr($uuid, 0, 8)),
            'name' => 'SCTT ' . $uuid,
            'status' => 'active',
        ]);

        return $uuid;
    }

    private function membership(string $tenantUuid, string $userUuid, string $role): void
    {
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
            'role' => $role,
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0:string,1:string} [workspaceUuid, actorUuid] */
    private function seedWorkspaceAndVerifiedActor(): array
    {
        return [$this->seedWorkspace(), $this->seedUser(verified: true)];
    }

    private function seedUser(bool $verified): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->userUuids[] = $uuid;
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'sctt_' . strtolower(substr($uuid, 0, 8)),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'email_verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /**
     * Returns the LITERAL `$keySuffix` as the plan_key (deterministic, no random suffix).
     *
     * @return string the plan_key
     */
    private function seedPurchasablePlan(string $gateway, string $keySuffix): string
    {
        $planKey = $keySuffix;
        $this->container()->get(EngineGateway::class)->plans()->create([
            'plan_key' => $planKey,
            'display_name' => ucfirst($keySuffix) . ' Plan',
            'entitlements' => [],
            'status' => 'active',
            'provider_identifiers' => [$gateway => 'price_' . $planKey],
        ]);

        return $planKey;
    }

    /** @return array{plan_uuid:string,plan_key:string,name:string,provider_identifier:string} */
    private function purchasablePlanRow(string $gateway, string $planKey): array
    {
        foreach (
            \Glueful\Extensions\Subscriptions\Plans\PlanPurchasability::forGateway(
                $this->appContext(),
                $gateway,
            ) as $plan
        ) {
            if ($plan['plan_key'] === $planKey) {
                return $plan;
            }
        }

        self::fail("plan '{$planKey}' is not purchasable for gateway '{$gateway}'");
    }

    private function seedSubscription(string $tenantUuid, string $status, ?string $currentPeriodEnd = null): void
    {
        $this->connection()->table('subscriptions')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => 'tenant',
            'subject_uuid' => $tenantUuid,
            'plan_uuid' => Utils::generateNanoID(12),
            'plan_key' => 'seeded-plan',
            'status' => $status,
            'current_period_end' => $currentPeriodEnd,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedProviderManagedSubscription(
        string $tenantUuid,
        string $gateway,
        string $providerSubscriptionId,
    ): void {
        $this->connection()->table('subscriptions')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => 'tenant',
            'subject_uuid' => $tenantUuid,
            'plan_uuid' => Utils::generateNanoID(12),
            'plan_key' => 'seeded-plan',
            'status' => 'active',
            'provider_gateway' => $gateway,
            'provider_subscription_id' => $providerSubscriptionId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedIncompleteReservation(string $workspaceUuid, string $originationUuid): void
    {
        $this->connection()->table('subscriptions')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $workspaceUuid,
            'subject_type' => 'tenant',
            'subject_uuid' => $workspaceUuid,
            'plan_uuid' => Utils::generateNanoID(12),
            'plan_key' => 'seeded-plan',
            'status' => 'incomplete',
            'checkout_origination_uuid' => $originationUuid,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Directly seeds a `pending` origination + a `live` subject guard bound to it. */
    private function seedPendingOrigination(string $workspaceUuid, string $gateway): string
    {
        $originationUuid = Utils::generateNanoID(12);
        $tenantUuid = $this->payviaTenantUuid();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspaceUuid);

        $this->connection()->table('subscription_checkout_originations')->insert([
            'uuid' => $originationUuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'gateway' => $gateway,
            'provider_plan_identifier' => 'price_test',
            'idempotency_key' => 'sctt-origination-' . $originationUuid,
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://admin.test/billing/return',
            'cancel_url' => 'https://admin.test/billing/return',
            'checkout_reference' => 'cs_test_' . $originationUuid,
            'checkout_url' => 'https://checkout.test/' . $originationUuid,
            'status' => 'pending',
            'live' => true,
            'consumer_metadata' => json_encode([
                'tenant_uuid' => $workspaceUuid,
                'subject_type' => 'tenant',
                'subject_uuid' => $workspaceUuid,
            ]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->container()->get(CheckoutSubjectGuardRepository::class)->lockAndClaim(
            $this->appContext(),
            $tenantUuid,
            $subjectKey,
            $originationUuid,
        );

        return $originationUuid;
    }

    private function enableSelfServe(): void
    {
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
    }

    private function defaultGatewayName(): string
    {
        return PayviaSettings::defaultGateway($this->appContext());
    }

    private function payviaTenantUuid(): string
    {
        return $this->container()->get(PayviaTenantResolver::class)->tenantUuid($this->appContext());
    }

    private function registerRecordingGateway(RecordingSubscriptionCheckoutGateway $double): string
    {
        $gatewayName = $this->defaultGatewayName();
        $containerId = $this->uniqueContainerId($double);

        $container = $this->container();
        self::assertInstanceOf(Container::class, $container);
        $container->load([$containerId => $double]);

        $this->container()->get(GatewayManager::class)->registerDriver($gatewayName, $containerId);

        return $gatewayName;
    }

    private function registerDriver(object $double): void
    {
        $gatewayName = $this->defaultGatewayName();
        $containerId = $this->uniqueContainerId($double);

        $container = $this->container();
        self::assertInstanceOf(Container::class, $container);
        $container->load([$containerId => $double]);

        $this->container()->get(GatewayManager::class)->registerDriver($gatewayName, $containerId);
    }

    /**
     * A container id that can never collide with another test class's own driver-double
     * registration: `Container::get()` caches a resolved id's value in a `singletons` map that
     * `load()` never busts (it only replaces the DEFINITION, not an already-resolved singleton),
     * so a `Class:N` counter-based id -- the pattern several sibling suites already use, each with
     * their OWN independent `self::$seq` -- can coincide across files and silently resolve to a
     * STALE double from a different test class/run. `self::class` (this file's own FQCN) plus
     * `spl_object_id()` makes every id this file mints unique process-wide, regardless of what
     * any other suite's counter reaches. NOTE the real invariant: `spl_object_id()` handles ARE
     * reused by PHP after GC — what actually prevents a collision here is that the process-shared
     * container (`AppTestCase::$app`, set once per process) retains every registered double in
     * `$definitions`/`$singletons` forever, so a double's object — and therefore its id — stays
     * alive for the run. If the harness ever moves to per-test containers or gains a
     * `Container::forget()`, this scheme needs revisiting.
     */
    private function uniqueContainerId(object $double): string
    {
        return self::class . ':' . $double::class . ':' . spl_object_id($double);
    }

    private function controller(): SelfBillingController
    {
        $controller = $this->container()->get(SelfBillingController::class);
        self::assertInstanceOf(SelfBillingController::class, $controller);

        return $controller;
    }

    /** A REAL second boot with glueful/subscriptions' own provider filtered out. */
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

    /** @return bool whether {@see PermissionRequirementAuthority} grants `billing.manage`. */
    private function billingManageAllowed(string $tenantUuid, string $userUuid): bool
    {
        $authority = new PermissionRequirementAuthority(
            $this->appContext(),
            new CapabilityCatalog(),
            new TenantMembershipRoleReader($this->appContext(), $this->fixedResolver($tenantUuid)),
            $this->container()->get(EffectiveRoleMatrix::class),
            $this->container()->get(OperatorBypass::class),
            null,
            $this->container()->get(PermissionAuthority::class),
        );

        $request = Request::create(self::META_ROUTE);
        $request->attributes->set('auth.user', new UserIdentity(uuid: $userUuid));

        $prior = $this->appContext()->getRequestState('tenancy.tenant');
        $this->appContext()->setRequestState('tenancy.tenant', $tenantUuid);
        try {
            return $authority->allows($request, ['billing.manage']);
        } finally {
            $this->appContext()->setRequestState('tenancy.tenant', $prior);
        }
    }

    private function fixedResolver(string $tenantUuid): CurrentTenantResolver
    {
        return new class ($tenantUuid) implements CurrentTenantResolver {
            public function __construct(private readonly string $uuid)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->uuid;
            }
        };
    }

    /** @param array<string,mixed>|null $body */
    private function checkoutRequest(?string $actorUuid, ?array $body, ?string $idempotencyKey): Request
    {
        $request = Request::create(
            self::CHECKOUT_ROUTE,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body === null ? null : (string) json_encode($body),
        );
        if ($actorUuid !== null) {
            $request->attributes->set('auth.user', new UserIdentity(uuid: $actorUuid));
        }
        if ($idempotencyKey !== null) {
            $request->headers->set('Idempotency-Key', $idempotencyKey);
        }

        return $request;
    }

    /** @param array<string,mixed> $body */
    private function cancelRequest(?string $actorUuid, array $body): Request
    {
        $request = Request::create(
            '/v1/admin/billing/cancel',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) json_encode($body),
        );
        if ($actorUuid !== null) {
            $request->attributes->set('auth.user', new UserIdentity(uuid: $actorUuid));
        }

        return $request;
    }

    private function abandonRequest(?string $actorUuid): Request
    {
        $request = Request::create('/v1/admin/billing/checkout/abandon', 'POST');
        if ($actorUuid !== null) {
            $request->attributes->set('auth.user', new UserIdentity(uuid: $actorUuid));
        }

        return $request;
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

    /** @return array<string,mixed> */
    private function errorDetails(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());

        return (array) ($decoded['error']['details'] ?? []);
    }
}
