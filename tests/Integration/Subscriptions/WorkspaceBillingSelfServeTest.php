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
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Payvia\Checkout\DefinitiveSubscriptionCheckoutRejection;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Subscriptions\CheckoutReservationException;
use Glueful\Extensions\Subscriptions\Plans\PlanPurchasability;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Checkout\PayviaCheckoutGateway;
use Thallo\Subscriptions\Checkout\WorkspaceCheckoutCoordinator;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\SelfBillingController;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): the workspace billing API --
 * `GET /v1/admin/billing/meta` + `POST /v1/admin/billing/checkout`
 * ({@see \Thallo\Subscriptions\Http\SelfBillingController}).
 *
 * Mirrors `WorkspaceBillingApiTest`'s (Task 9) direct-construction idiom for functional coverage
 * (single-store default workspace, real container-resolved collaborators) and
 * `BillingManageCapabilityTest`'s (Task 14) hand-built-authority idiom for the `billing.manage`
 * matrix -- `admin_tenant_binding`'s full tenant-resolution readiness needs the enforcement
 * provider this app's test harness deliberately strips (`config/testing/extensions.php`), so a
 * real end-to-end 200 through the full HTTP kernel is not obtainable without that heavier,
 * opt-in retrofit harness; testing `PermissionRequirementAuthority::allows()` directly -- the
 * SAME instance `content_permission` middleware resolves from the container -- against a REAL
 * `tenancy.tenant` request-state stamp is the established substitute (see
 * `AdminAuthorizationMatrixTest`'s own docblock).
 *
 * The happy-path/failure-matrix coverage runs against a {@see RecordingSubscriptionCheckoutGateway}
 * double registered onto the REAL, shared `GatewayManager` under this environment's actual
 * configured default gateway name (mirrors `SelfServeSwitchTest`'s established
 * `registerDriver()`-swap idiom) -- so `WorkspaceCheckoutCoordinator`/`SubscriptionCheckoutService`
 * /`SubscriptionService::reserveCheckoutFor()` all run for REAL against the real `app_test`
 * ledger + engine tables; only the actual provider HTTP call is stubbed.
 */
final class WorkspaceBillingSelfServeTest extends AppTestCase
{
    private const META_ROUTE = '/v1/admin/billing/meta';
    private const CHECKOUT_ROUTE = '/v1/admin/billing/checkout';

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
        if ($this->tenantUuids !== []) {
            $db = $this->connection();
            $db->table('tenant_memberships')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenant_role_overrides')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        if ($this->userUuids !== []) {
            $this->connection()->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db = $this->connection();
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->tenantUuids = [];
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    private function resetState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM subscription_checkout_originations');
        $pdo->exec('DELETE FROM subscription_checkout_subject_guards');
        $pdo->exec('DELETE FROM subscriptions');
        $pdo->exec('DELETE FROM subscription_plans');
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'subscriptions.self_serve_checkout_enabled')
            ->delete();
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'tenancy.default_tenant_uuid')
            ->delete();
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    // ==================================================================
    // Structural pins
    // ==================================================================

    public function testRoutesAreRegisteredWithTheExactChainAndName(): void
    {
        $expectedMiddleware = ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'];

        $meta = $this->findRoute('GET', self::META_ROUTE);
        self::assertNotNull($meta, 'GET ' . self::META_ROUTE . ' must be registered');
        self::assertSame('thallo.subscriptions.billing.meta', $meta['name']);
        foreach ($expectedMiddleware as $mw) {
            self::assertContains($mw, (array) $meta['middleware']);
        }
        self::assertContains('content_permission:billing.manage', (array) $meta['middleware']);

        $checkout = $this->findRoute('POST', self::CHECKOUT_ROUTE);
        self::assertNotNull($checkout, 'POST ' . self::CHECKOUT_ROUTE . ' must be registered');
        self::assertSame('thallo.subscriptions.billing.checkout', $checkout['name']);
        foreach ($expectedMiddleware as $mw) {
            self::assertContains($mw, (array) $checkout['middleware']);
        }
        self::assertContains('content_permission:billing.manage', (array) $checkout['middleware']);
    }

    public function testUnauthenticatedRequestsAreRejectedWith401(): void
    {
        $get = $this->handle($this->jsonRequest('GET', self::META_ROUTE));
        self::assertSame(401, $get->getStatusCode());

        $post = $this->handle($this->jsonRequest('POST', self::CHECKOUT_ROUTE, ['plan_key' => 'irrelevant']));
        self::assertSame(401, $post->getStatusCode());
    }

    // ==================================================================
    // Authority matrix (billing.manage): owner/delegated/member/platform-only
    // ==================================================================

    public function testAuthorityMatrixForBillingManage(): void
    {
        $tenantUuid = $this->seedTenant();
        $owner = Utils::generateNanoID(12);
        $member = Utils::generateNanoID(12);
        $delegate = Utils::generateNanoID(12);
        $this->membership($tenantUuid, $owner, 'owner');
        $this->membership($tenantUuid, $member, 'member');
        $this->membership($tenantUuid, $delegate, 'viewer');

        $repository = $this->container()->get(TenantRoleOverrideRepository::class);
        self::assertInstanceOf(TenantRoleOverrideRepository::class, $repository);
        $this->connection()->transaction(fn () => $repository->reconcileRoleOverridesInTransaction(
            $tenantUuid,
            'viewer',
            ['billing.manage'],
            [],
            null,
        ));

        self::assertTrue(
            $this->billingManageAllowed($tenantUuid, $owner),
            'owner must be granted billing.manage',
        );
        self::assertTrue(
            $this->billingManageAllowed($tenantUuid, $delegate),
            'a workspace role delegated billing.manage must be granted it',
        );
        self::assertFalse(
            $this->billingManageAllowed($tenantUuid, $member),
            'a plain member without a grant must be denied',
        );

        $operator = $this->platformOperatorUser();
        self::assertFalse(
            $this->billingManageAllowed($tenantUuid, $operator),
            'a platform-only operator (tenancy.manage + tenancy.access_any, no workspace membership)'
                . ' must be denied -- billing.manage and tenancy.manage are disjoint authorities',
        );
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

        $request = Request::create('/v1/admin/billing/meta');
        $request->attributes->set('auth.user', new UserIdentity(uuid: $userUuid));

        // Stamp the SAME 'tenancy.tenant' request-state key `admin_tenant_binding`'s real
        // `runAsTenant()` call would set for this request -- `PermissionRequirementAuthority::
        // allows()` gates its whole tenant-role-matrix branch on this being non-null.
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

    // ==================================================================
    // /meta: 200 shape, live/blocked origination states
    // ==================================================================

    public function testMetaShapeWithNoSubscriptionOrOrigination(): void
    {
        $this->seedWorkspace();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');

        $response = $this->controller()->meta(Request::create('/', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->data($response);

        self::assertSame(EngineGateway::READY, $body['engine']);
        self::assertTrue($body['self_serve_checkout_enabled']);
        self::assertNotSame('', $body['workspace_uuid']);
        self::assertNull($body['subscription']);
        self::assertNull($body['origination']);
        self::assertFalse($body['operator_contact_required']);
        self::assertNull($body['operator_contact_reason']);
        self::assertCount(1, $body['purchasable_plans']);
        self::assertArrayNotHasKey('can_manage_billing', $body);
        self::assertArrayHasKey('plan_key', $body['purchasable_plans'][0]);
        self::assertArrayHasKey('name', $body['purchasable_plans'][0]);
        self::assertCount(2, $body['purchasable_plans'][0], 'plan_key + name ONLY -- no prices/uuid');
    }

    public function testMetaIsTwoHundredEvenWhenSelfServeDisabled(): void
    {
        $this->seedWorkspace();
        // Deliberately not enabling self-serve, and no capable gateway registered.
        $response = $this->controller()->meta(Request::create('/', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->data($response);
        self::assertFalse($body['self_serve_checkout_enabled']);
        self::assertSame([], $body['purchasable_plans']);
    }

    public function testMetaShowsLiveOriginationWithStoredCheckoutUrl(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gateway = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gateway, 'starter');

        $checkout = $this->controller();
        $request = $this->checkoutRequest($actor, ['plan_key' => 'starter'], 'meta-live-key-0001');
        $response = $checkout->checkout($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $meta = $checkout->meta(Request::create('/', 'GET'));
        $body = $this->data($meta);
        self::assertNotNull($body['origination']);
        self::assertSame('pending', $body['origination']['status']);
        self::assertNotNull($body['origination']['checkout_url']);
        self::assertFalse($body['operator_contact_required']);
    }

    public function testMetaShowsOperatorContactStateForABlockedGuard(): void
    {
        $this->seedWorkspace();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($this->currentWorkspaceUuid());
        $this->container()->get(CheckoutSubjectGuardRepository::class)->block(
            $this->appContext(),
            $this->payviaTenantUuid(),
            $subjectKey,
            null,
            'late_settlement_conflict',
        );

        $response = $this->controller()->meta(Request::create('/', 'GET'));
        $body = $this->data($response);
        self::assertNull($body['origination']);
        self::assertTrue($body['operator_contact_required']);
        self::assertSame('late_settlement_conflict', $body['operator_contact_reason']);
    }

    // ==================================================================
    // Idempotency-Key header validation (422)
    // ==================================================================

    public function testMissingOrMalformedIdempotencyKeyIsRejectedWith422(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $controller = $this->controller();

        $missing = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], null));
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('invalid_idempotency_key', $this->errorCode($missing));

        $tooShort = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], 'short'));
        self::assertSame(422, $tooShort->getStatusCode());
        self::assertSame('invalid_idempotency_key', $this->errorCode($tooShort));

        $tooLong = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], str_repeat('a', 129)),
        );
        self::assertSame(422, $tooLong->getStatusCode());

        $badChars = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], str_repeat('a', 15) . ' '),
        );
        self::assertSame(422, $badChars->getStatusCode());

        // Fix round I8 (minor): a value whose TRIMMED content would be valid, but whose RAW
        // header value carries padding, must still be rejected -- proving validation runs
        // against the raw header, never a trim()'d copy (a trim-then-validate bug would have
        // silently accepted this).
        $padded = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], '  ' . str_repeat('b', 20) . '  '),
        );
        self::assertSame(422, $padded->getStatusCode());
        self::assertSame('invalid_idempotency_key', $this->errorCode($padded));

        self::assertSame(
            0,
            $this->connection()->table('subscription_checkout_originations')->count(),
            'no origination may be written for a rejected Idempotency-Key',
        );
    }

    /**
     * Fix round I8 (minor): `plan_key` is bounded (`/^[a-z0-9._-]{1,100}$/`) and rejected 422
     * BEFORE ever reaching the purchasability lookup -- an over-length or invalid-character value
     * must never be echoed back unbounded in an error message either.
     */
    public function testMalformedPlanKeyIsRejectedWith422(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $controller = $this->controller();

        $missing = $controller->checkout($this->checkoutRequest($actor, [], $this->key()));
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('invalid_plan_key', $this->errorCode($missing));

        $uppercase = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'Starter-Plan'], $this->key()),
        );
        self::assertSame(422, $uppercase->getStatusCode());
        self::assertSame('invalid_plan_key', $this->errorCode($uppercase));

        $tooLong = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => str_repeat('a', 101)], $this->key()),
        );
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertSame('invalid_plan_key', $this->errorCode($tooLong));

        self::assertSame(0, $this->connection()->table('subscription_checkout_originations')->count());
    }

    // ==================================================================
    // Switch-off + request-time recheck
    // ==================================================================

    public function testSwitchOffRefusesCheckoutAndIsRecheckedAtRequestTime(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $controller = $this->controller();

        // Switch never enabled.
        $response = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('self_serve_disabled', $this->errorCode($response));

        // Enable -> succeeds.
        $this->enableSelfServe();
        $enabledResponse = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertNotSame(409, $enabledResponse->getStatusCode());

        // Disable mid-session -> refused again immediately (never cached).
        $this->disableSelfServe();
        $disabledAgain = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $disabledAgain->getStatusCode());
        self::assertSame('self_serve_disabled', $this->errorCode($disabledAgain));
    }

    // ==================================================================
    // Verified-email matrix -- refused BEFORE any payvia/subscriptions write
    // ==================================================================

    public function testMissingAccountRefusesBeforeAnyWrite(): void
    {
        [$workspace, ] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');

        $unknownActor = Utils::generateNanoID(12); // never inserted into `users`
        $response = $this->controller()->checkout(
            $this->checkoutRequest($unknownActor, ['plan_key' => 'starter'], $this->key()),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('verified_email_required', $this->errorCode($response));
        self::assertSame(0, $this->connection()->table('subscription_checkout_originations')->count());
        self::assertSame(0, $this->connection()->table('subscriptions')->count());
    }

    public function testUnverifiedEmailRefusesBeforeAnyWrite(): void
    {
        $this->seedWorkspace();
        $actor = $this->seedUser(verified: false);
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('verified_email_required', $this->errorCode($response));
        self::assertSame(0, $this->connection()->table('subscription_checkout_originations')->count());
        self::assertSame(0, $this->connection()->table('subscriptions')->count());
    }

    public function testDeletedAccountRefusesBeforeAnyWrite(): void
    {
        $this->seedWorkspace();
        $actor = $this->seedUser(verified: true, softDeleted: true);
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('verified_email_required', $this->errorCode($response));
        self::assertSame(0, $this->connection()->table('subscription_checkout_originations')->count());
    }

    public function testForgedRequestAndUserAttributeEmailAreIgnored(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor('real-verified@example.test');
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gateway = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gateway, 'starter');

        $request = $this->checkoutRequest(
            $actor,
            ['plan_key' => 'starter', 'email' => 'forged-body@example.test'],
            $this->key(),
        );
        // Forge the principal's own carried email too -- never accepted as the receipt address.
        $request->attributes->set('auth.user', new UserIdentity(uuid: $actor, email: 'forged-jwt@example.test'));

        $response = $this->controller()->checkout($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertCount(1, $gatewayDouble->requests);
        self::assertSame('real-verified@example.test', $gatewayDouble->requests[0]->customerEmail);
    }

    // ==================================================================
    // Refusal matrix: already-active, checkout_pending, checkout_blocked, plan_not_purchasable
    // ==================================================================

    public function testActiveSubscriptionRefusesWithSubscriptionAlreadyActive(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $this->seedSubscription($workspace, 'active');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('subscription_already_active', $this->errorCode($response));
    }

    public function testUnexpiredNonRenewingRefusesWithSubscriptionAlreadyActive(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $this->seedSubscription($workspace, 'non_renewing', gmdate('Y-m-d H:i:s', time() + 86400));

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('subscription_already_active', $this->errorCode($response));
    }

    public function testExpiredNonRenewingDoesNotRefuse(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');
        $this->seedSubscription($workspace, 'non_renewing', gmdate('Y-m-d H:i:s', time() - 86400));

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertNotSame(409, $response->getStatusCode());
    }

    public function testDifferentKeyAgainstALiveOriginationRefusesWithCheckoutPendingAndStoredUrl(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gateway = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gateway, 'starter');
        $controller = $this->controller();

        $first = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], 'first-attempt-key0'));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $firstUrl = $this->data($first)['checkout_url'];
        self::assertNotNull($firstUrl);

        $second = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], 'second-different-key'),
        );
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('checkout_pending', $this->errorCode($second));
        $details = $this->errorDetails($second);
        self::assertSame($firstUrl, $details['checkout_url']);

        // Only ONE provider call happened -- the second attempt never reached prepare()/the gateway.
        self::assertSame(1, $gatewayDouble->calls);
    }

    public function testSameKeyResumesInsteadOfBeingPreemptedByCheckoutPending(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gateway = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gateway, 'starter');
        $controller = $this->controller();

        $key = 'resume-same-key-00001';
        $first = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(200, $first->getStatusCode());

        $resume = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(200, $resume->getStatusCode(), (string) $resume->getContent());
        self::assertSame($this->data($first), $this->data($resume));
        // No second provider call -- the resume replays the stored `pending` result.
        self::assertSame(1, $gatewayDouble->calls);

        self::assertSame(1, $this->connection()->table('subscription_checkout_originations')->count());
    }

    public function testPlanChangeUnderTheSameKeyIsAnIdempotencyConflict(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gateway = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gateway, 'planaaa');
        $this->seedPurchasablePlan($gateway, 'planbbb');
        $controller = $this->controller();

        $key = 'plan-change-same-key1';
        $first = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'planaaa'], $key));
        self::assertSame(200, $first->getStatusCode());

        $changed = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'planbbb'], $key));
        self::assertSame(409, $changed->getStatusCode(), (string) $changed->getContent());
        self::assertSame('idempotency_conflict', $this->errorCode($changed));
    }

    public function testBlockedGuardRefusesWithCheckoutBlocked(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gateway = $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        $this->seedPurchasablePlan($gateway, 'starter');

        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspace);
        $this->container()->get(CheckoutSubjectGuardRepository::class)->block(
            $this->appContext(),
            $this->payviaTenantUuid(),
            $subjectKey,
            null,
            'projection_rejected',
        );

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('checkout_blocked', $this->errorCode($response));
    }

    public function testUnknownPlanKeyRefusesWithPlanNotPurchasable(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $this->registerRecordingGateway(new RecordingSubscriptionCheckoutGateway());
        // No plan seeded at all.

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'does-not-exist'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('plan_not_purchasable', $this->errorCode($response));
    }

    public function testGatewayWithoutSubscriptionCheckoutCapabilityRefusesWithPlanNotPurchasable(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        // Deliberately do NOT register a capable double -- the real baseline driver
        // (PaystackGateway, restored by resetGatewayDriver()) does not implement
        // SubscriptionInitiationCapableGateway per payvia 2.5's pinned sandbox-proof outcome.
        $this->seedPurchasablePlan($this->defaultGatewayName(), 'starter');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('plan_not_purchasable', $this->errorCode($response));
    }

    // ==================================================================
    // Happy path + crash/rejection recovery
    // ==================================================================

    public function testHappyPathCreatesReservationAndOriginationAtomicallyAndReturnsPending(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gatewayName, 'starter');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->data($response);
        self::assertSame('pending', $body['status']);
        self::assertNotNull($body['checkout_url']);

        $originations = $this->connection()->table('subscription_checkout_originations')->get();
        self::assertCount(1, $originations);
        self::assertSame('pending', $originations[0]['status']);

        $subscription = $this->container()->get(SubscriptionService::class)->current($workspace);
        self::assertNotNull($subscription);
        self::assertSame('incomplete', $subscription['status'], 'reserveCheckoutFor() must never entitle');
        self::assertSame($originations[0]['uuid'], $subscription['checkout_origination_uuid']);

        // Consumer metadata: closed map per spec §5.2. Key ORDER is not significant -- the
        // request handed to `initializeSubscription()` was reconstructed from the ledger's
        // stored JSON(B) column (`requestFromRow()`), which does not promise to preserve
        // insertion order -- so both sides are compared key-sorted.
        self::assertCount(1, $gatewayDouble->requests);
        $sent = $gatewayDouble->requests[0];
        self::assertSame('subscriptions', $sent->requiredProjectionConsumer);
        $expectedMetadata = [
            'tenant_uuid' => $workspace,
            'subject_type' => 'tenant',
            'subject_uuid' => $workspace,
            'plan_uuid' => $subscription['plan_uuid'],
            'glueful_consumer' => 'subscriptions',
            'actor_user_uuid' => $actor,
        ];
        $actualMetadata = $sent->consumerMetadata;
        ksort($expectedMetadata);
        ksort($actualMetadata);
        self::assertSame($expectedMetadata, $actualMetadata);
        self::assertStringContainsString('/billing/return?origination=', $sent->returnUrl);
    }

    public function testProviderFailureAfterCommitLeavesReservationAndInitializingForSameKeyRecovery(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayDouble->throw = new \RuntimeException('simulated network blip');
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gatewayName, 'starter');
        $controller = $this->controller();

        $key = 'recovery-key-000001';
        $response = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->data($response);
        self::assertSame('initializing', $body['status']);
        self::assertNull($body['checkout_url']);

        $origination = $this->connection()->table('subscription_checkout_originations')->first();
        self::assertNotNull($origination);
        self::assertSame('initializing', $origination['status']);

        $subscription = $this->container()->get(SubscriptionService::class)->current($workspace);
        self::assertNotNull($subscription, 'the reservation must survive an unknown-outcome provider failure');
        self::assertSame('incomplete', $subscription['status']);

        // Same-key recovery: the retry resumes and now succeeds.
        $gatewayDouble->throw = null;
        $recovered = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(200, $recovered->getStatusCode(), (string) $recovered->getContent());
        self::assertSame('pending', $this->data($recovered)['status']);
        self::assertSame(2, $gatewayDouble->calls, 'the recovery call must reach the provider again');
    }

    public function testDefinitiveRejectionLeavesReservationAndFailedOrigination(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayDouble->throw = DefinitiveSubscriptionCheckoutRejection::forStripeError(
            ['message' => 'Your card was declined.', 'code' => 'card_declined'],
            ['error' => ['message' => 'Your card was declined.']],
        );
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gatewayName, 'starter');

        $response = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        // Fix round I6: a terminal `failed` result is a genuine refusal (409 `checkout_failed`),
        // not an informational 200 -- the attempt is definitively dead and the caller must mint a
        // NEW idempotency key for another attempt.
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('checkout_failed', $this->errorCode($response));
        self::assertSame('failed', $this->errorDetails($response)['status'] ?? null);

        $origination = $this->connection()->table('subscription_checkout_originations')->first();
        self::assertNotNull($origination);
        self::assertSame('failed', $origination['status']);

        // Reservation still exists (reserveCheckoutFor() ran before provider I/O and is never
        // undone by a definitive provider rejection -- only the guard/origination resolve).
        $subscription = $this->container()->get(SubscriptionService::class)->current($workspace);
        self::assertNotNull($subscription);
        self::assertSame('incomplete', $subscription['status']);

        // The subject guard is freed -- a fresh attempt (new key) is now possible.
        $again = $this->controller()->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertNotSame(409, $again->getStatusCode(), (string) $again->getContent());
    }

    /**
     * Fix round 2 (code review, I6 residual): a same-key replay of an origination that has
     * already advanced to `provider_observed` (a webhook correlated it; the guard stays live,
     * the subscription reservation is not yet entitling) must NEVER re-serve the stored
     * `checkout_url` -- the session is spent (money has already moved), and re-serving it would
     * invite a double-payment attempt against an already-completing session. This is a
     * response-SHAPING rule only: the stored row itself keeps its `checkout_url` untouched.
     */
    public function testProviderObservedReplayNeverReservesTheStoredCheckoutUrl(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gatewayName, 'starter');
        $controller = $this->controller();

        $key = 'provider-observed-key-01';
        $first = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame('pending', $this->data($first)['status']);
        self::assertNotNull($this->data($first)['checkout_url']);

        // Simulate a webhook correlating this origination (direct SQL -- the guard stays live at
        // this status per the state machine; only a TERMINAL status frees it).
        $this->connection()->table('subscription_checkout_originations')
            ->where('idempotency_key', '=', $key)
            ->update(['status' => 'provider_observed']);

        $replay = $controller->checkout($this->checkoutRequest($actor, ['plan_key' => 'starter'], $key));
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getContent());
        $body = $this->data($replay);
        self::assertSame('provider_observed', $body['status']);
        self::assertNull($body['checkout_url'], 'a provider_observed replay must never re-serve the stored URL');
        // No second provider call -- this is a pure replay of the stored row.
        self::assertSame(1, $gatewayDouble->calls);

        // Sanity: this is a response-shaping rule only -- the STORED row still carries the URL.
        $stored = $this->connection()->table('subscription_checkout_originations')
            ->where('idempotency_key', '=', $key)
            ->first();
        self::assertNotNull($stored);
        self::assertNotNull($stored['checkout_url']);
    }

    // ==================================================================
    // Fix round I2: deterministic origination uuid derivation
    // ==================================================================

    /**
     * Two INDEPENDENT executions of the minting path (no committed row exists for the key on
     * either call -- the first call's whole transaction is forced to roll back before the
     * second ever runs) must derive the IDENTICAL origination uuid for the same (tenant,
     * idempotency key) pair. This is what makes a genuinely concurrent same-key retry safe: if
     * the uuid were randomly minted per call, two callers racing before either commits would
     * embed DIFFERENT uuids into `returnUrl`/`cancelUrl`, and Payvia's fingerprint (which hashes
     * those URLs) would then disagree between them -- turning a legitimate concurrent retry into
     * a spurious `idempotency_conflict`.
     */
    public function testOriginationUuidDerivationIsDeterministicAcrossIndependentMintingCalls(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $planKey = $this->seedPurchasablePlan($gatewayName, 'starter');
        $plan = $this->purchasablePlanRow($gatewayName, $planKey);
        $key = $this->key();
        $expectedUuid = substr(hash('sha256', $workspace . ':' . $key), 0, 12);

        // First call: the continuation (reserveCheckoutFor) is forced to refuse
        // (already_subscribed), so prepare()'s single owning transaction rolls back everything --
        // the minting path runs, computes a uuid, but commits NO row at all.
        $this->seedSubscription($workspace, 'active');
        try {
            $this->buildCoordinator($gatewayName)->prepare(
                $this->appContext(),
                $workspace,
                $plan,
                $actor,
                'verified@example.test',
                $key,
                $gatewayName,
                'https://admin.test/billing/return',
            );
            self::fail('expected a CheckoutReservationException');
        } catch (CheckoutReservationException $e) {
            self::assertSame('already_subscribed', $e->reasonCode);
        }
        self::assertSame(0, $this->connection()->table('subscription_checkout_originations')->count());

        // Second call, independently constructed, SAME (tenant, key) -- the row still does not
        // exist, so this ALSO takes the minting path. It must derive the exact same uuid as the
        // first call would have, proving the formula is a pure function of its inputs.
        $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->delete();
        $claim = $this->buildCoordinator($gatewayName)->prepare(
            $this->appContext(),
            $workspace,
            $plan,
            $actor,
            'verified@example.test',
            $key,
            $gatewayName,
            'https://admin.test/billing/return',
        );

        self::assertSame($expectedUuid, $claim->originationUuid);
        $stored = $this->connection()->table('subscription_checkout_originations')->first();
        self::assertNotNull($stored);
        self::assertSame($expectedUuid, $stored['uuid']);
    }

    // ==================================================================
    // Fix round I5: atomicity -- a continuation refusal rolls back EVERYTHING
    // ==================================================================

    /**
     * Forces `reserveCheckoutFor()` (the continuation `prepare()` invokes inside its single
     * owning transaction) to refuse by pre-seeding an active subscription -- proving the whole
     * transaction rolls back together: zero rows in the origination ledger, zero rows in the
     * subject guard table, and the `subscriptions` table is left with ONLY the pre-seeded row
     * (no new reservation was ever written).
     */
    public function testContinuationFailureRollsBackOriginationGuardAndReservationAtomically(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $planKey = $this->seedPurchasablePlan($gatewayName, 'starter');
        $plan = $this->purchasablePlanRow($gatewayName, $planKey);
        $this->seedSubscription($workspace, 'active');

        try {
            $this->buildCoordinator($gatewayName)->prepare(
                $this->appContext(),
                $workspace,
                $plan,
                $actor,
                'verified@example.test',
                $this->key(),
                $gatewayName,
                'https://admin.test/billing/return',
            );
            self::fail('expected a CheckoutReservationException');
        } catch (CheckoutReservationException $e) {
            self::assertSame('already_subscribed', $e->reasonCode);
        }

        self::assertSame(
            0,
            $this->connection()->table('subscription_checkout_originations')->count(),
            'no origination row may survive a rolled-back prepare()',
        );
        self::assertSame(
            0,
            $this->connection()->table('subscription_checkout_subject_guards')->count(),
            'no subject guard row may survive a rolled-back prepare()',
        );
        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'ONLY the pre-seeded active subscription may exist -- no new reservation was committed',
        );
        self::assertSame(0, $gatewayDouble->calls, 'the provider must never be called for a rolled-back attempt');
    }

    // ==================================================================
    // Fix round Critical C1: graceful degradation when Payvia/Users are unavailable
    // ==================================================================

    /**
     * Proves the actual bug C1 named: `SelfBillingController` must stay CONSTRUCTIBLE and
     * `GET /meta` must stay 200 even when Payvia's checkout-ledger services are entirely absent
     * from the container (its provider disabled, or the specific services this pack depends on
     * unbound) -- and `POST /checkout` must degrade to a structured 409, never propagate a
     * container "service not found" exception as a 500.
     */
    public function testMetaAndCheckoutDegradeGracefullyWhenPayviaIsUnavailable(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $controller = $this->controllerHidingServices([
            CheckoutOriginationRepository::class,
            CheckoutSubjectGuardRepository::class,
            SubscriptionCheckoutService::class,
            PayviaTenantResolver::class,
        ]);

        $metaResponse = $controller->meta(Request::create('/', 'GET'));
        self::assertSame(200, $metaResponse->getStatusCode(), (string) $metaResponse->getContent());
        $body = $this->data($metaResponse);
        self::assertNull($body['origination']);
        self::assertFalse($body['operator_contact_required']);

        $checkoutResponse = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'irrelevant'], $this->key()),
        );
        self::assertSame(409, $checkoutResponse->getStatusCode());
        self::assertSame('payvia_unavailable', $this->errorCode($checkoutResponse));
    }

    /** The `glueful/users` counterpart of the test above. */
    public function testCheckoutDegradesGracefullyWhenUsersIsUnavailable(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();
        $gatewayDouble = new RecordingSubscriptionCheckoutGateway();
        $gatewayName = $this->registerRecordingGateway($gatewayDouble);
        $this->seedPurchasablePlan($gatewayName, 'starter');

        $controller = $this->controllerHidingServices([UserRepository::class]);

        $response = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'starter'], $this->key()),
        );
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('users_unavailable', $this->errorCode($response));
    }

    /**
     * Fix round 2 (code review, I8 residual): `PayviaCheckoutGateway::isAvailable()` distinguishes
     * "the extension isn't bound at all" from "it's bound but its ledger tables aren't migrated"
     * -- proven directly here against a genuinely empty (never-migrated) SQLite connection rather
     * than a stubbed schema-builder double, so the assertion exercises the REAL `hasTable()` path.
     */
    public function testUnavailableReasonDistinguishesExtensionAbsentFromSchemaNotReady(): void
    {
        $available = $this->container()->get(PayviaCheckoutGateway::class);
        self::assertTrue($available->isAvailable());
        self::assertNull($available->unavailableReason());

        $extensionAbsent = new PayviaCheckoutGateway(
            $this->contextHidingServices([
                CheckoutOriginationRepository::class,
                CheckoutSubjectGuardRepository::class,
                SubscriptionCheckoutService::class,
                PayviaTenantResolver::class,
            ]),
        );
        self::assertFalse($extensionAbsent->isAvailable());
        self::assertSame(
            PayviaCheckoutGateway::REASON_EXTENSION_UNAVAILABLE,
            $extensionAbsent->unavailableReason(),
        );

        $schemaNotReady = new PayviaCheckoutGateway($this->contextWithEmptyConnection());
        self::assertFalse($schemaNotReady->isAvailable());
        self::assertSame(
            PayviaCheckoutGateway::REASON_SCHEMA_NOT_READY,
            $schemaNotReady->unavailableReason(),
        );
    }

    /**
     * Fix round 2 (code review, I8 residual): the controller-level proof -- Payvia's provider
     * bound and its four services resolvable, but its OWN ledger tables genuinely absent (a real,
     * never-migrated SQLite connection swapped in for `Connection::class`, never a stubbed
     * schema-builder double) -- `GET /meta` stays 200 (origination state simply unreported) and
     * `POST /checkout` answers the SAME `payvia_unavailable` 409 code as the extension-absent
     * case, with `reason` distinguishing the two for operator diagnosis.
     */
    public function testMetaAndCheckoutDegradeGracefullyWhenPayviaLedgerSchemaIsNotMigrated(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->enableSelfServe();

        $context = $this->contextWithEmptyConnection();
        $controller = new SelfBillingController(
            $context,
            $this->container()->get(EngineGateway::class),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SelfServeCheckoutSetting::class),
            $this->container()->get(SelfServeGatewayCapability::class),
            new PayviaCheckoutGateway($context),
        );

        $metaResponse = $controller->meta(Request::create('/', 'GET'));
        self::assertSame(200, $metaResponse->getStatusCode(), (string) $metaResponse->getContent());
        $body = $this->data($metaResponse);
        self::assertNull($body['origination']);
        self::assertFalse($body['operator_contact_required']);

        $checkoutResponse = $controller->checkout(
            $this->checkoutRequest($actor, ['plan_key' => 'irrelevant'], $this->key()),
        );
        self::assertSame(409, $checkoutResponse->getStatusCode(), (string) $checkoutResponse->getContent());
        self::assertSame('payvia_unavailable', $this->errorCode($checkoutResponse));
        self::assertSame(
            PayviaCheckoutGateway::REASON_SCHEMA_NOT_READY,
            $this->errorDetails($checkoutResponse)['reason'] ?? null,
        );
    }

    /** A fresh, genuinely empty (never-migrated) in-memory SQLite connection -- no tables at all. */
    private function emptyConnection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    /** An `ApplicationContext` wrapping the shared container but with `Connection::class` swapped. */
    private function contextWithEmptyConnection(): ApplicationContext
    {
        $real = $this->appContext();
        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $connection = $this->emptyConnection();
        $context->setContainer(new class ($this->container(), $connection) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $real,
                private readonly Connection $connection,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === Connection::class) {
                    return $this->connection;
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === Connection::class || $this->real->has($id);
            }
        });

        return $context;
    }

    /**
     * Builds an `ApplicationContext` wrapping the shared container but reporting `has()/get()` as
     * absent for the given ids -- the context-only half of {@see self::controllerHidingServices()}.
     *
     * @param list<string> $hiddenIds
     */
    private function contextHidingServices(array $hiddenIds): ApplicationContext
    {
        $real = $this->appContext();
        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($this->container(), $hiddenIds) implements ContainerInterface {
            /** @param list<string> $hidden */
            public function __construct(private readonly ContainerInterface $real, private readonly array $hidden)
            {
            }

            public function get(string $id): mixed
            {
                if (in_array($id, $this->hidden, true)) {
                    throw new class ('hidden for this test') extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface
                    {
                    };
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return !in_array($id, $this->hidden, true) && $this->real->has($id);
            }
        });

        return $context;
    }

    /**
     * Builds a REAL `SelfBillingController` whose `ApplicationContext` wraps the shared container
     * but reports `has()/get()` as absent for the given ids -- mirrors `WorkspaceBillingApiTest`'s
     * (Task 9) established `workspaceControllerWithRunner()` container-wrapping idiom.
     *
     * @param list<string> $hiddenIds
     */
    private function controllerHidingServices(array $hiddenIds): SelfBillingController
    {
        $context = $this->contextHidingServices($hiddenIds);

        return new SelfBillingController(
            $context,
            $this->container()->get(EngineGateway::class),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SelfServeCheckoutSetting::class),
            $this->container()->get(SelfServeGatewayCapability::class),
            new PayviaCheckoutGateway($context),
        );
    }

    // ==================================================================
    // Concurrency (pgsql-gated): two attempts, two plans, exactly one live guard
    // ==================================================================

    public function testConcurrentDifferentPlanAttemptsYieldExactlyOneLiveGuardAndOneLoser(): void
    {
        if ((string) (getenv('DB_DRIVER') ?: '') !== 'pgsql') {
            self::markTestSkipped('Race proof requires the real PostgreSQL test database.');
        }

        $tenantUuid = Utils::generateNanoID(12);
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => 'race-' . strtolower(substr($tenantUuid, 0, 6)),
            'name' => 'Race Workspace',
            'status' => 'active',
        ]);
        $this->tenantUuids[] = $tenantUuid;
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $tenantUuid);
        $this->enableSelfServe();

        $actorUuid = $this->seedUser(verified: true);
        $gatewayName = $this->defaultGatewayName();
        $planA = $this->seedPurchasablePlan($gatewayName, 'racea');
        $planB = $this->seedPurchasablePlan($gatewayName, 'raceb');

        $handleA = $this->launchRaceChild([
            'tenant' => $tenantUuid,
            'actor' => $actorUuid,
            'gateway' => $gatewayName,
            'planKey' => $planA,
            'idempotencyKey' => 'race-plan-a-key-0001',
        ]);
        $handleB = $this->launchRaceChild([
            'tenant' => $tenantUuid,
            'actor' => $actorUuid,
            'gateway' => $gatewayName,
            'planKey' => $planB,
            'idempotencyKey' => 'race-plan-b-key-0001',
        ]);

        $resultA = $this->collectRaceChild($handleA);
        $resultB = $this->collectRaceChild($handleB);

        self::assertTrue($resultA['ok'] ?? false, 'child A must run without a fatal error: ' . json_encode($resultA));
        self::assertTrue($resultB['ok'] ?? false, 'child B must run without a fatal error: ' . json_encode($resultB));

        // Fix round I8 (minor): the previous version of this assertion contradicted itself (a
        // hardcoded [409, 200] pair that could never match a 202 winner). Winner-agnostic per
        // ShopCheckoutRaceTest's own established convention: exactly one racer is refused 409,
        // and the other reaches a winning status (200 `pending`, or 202 `initializing` if it
        // merely won the lease but the provider call had not completed by the time this process
        // read its response).
        $statuses = [(int) $resultA['status'], (int) $resultB['status']];
        $refused = array_values(array_filter($statuses, static fn (int $s): bool => $s === 409));
        $won = array_values(array_filter($statuses, static fn (int $s): bool => $s === 200 || $s === 202));
        self::assertCount(
            1,
            $refused,
            'exactly one racer must be refused 409: ' . json_encode([$resultA, $resultB]),
        );
        self::assertCount(
            1,
            $won,
            'exactly one racer must win (200 or 202): ' . json_encode([$resultA, $resultB]),
        );

        $guards = $this->connection()->table('subscription_checkout_subject_guards')
            ->where('tenant_uuid', '=', '')
            ->get();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($tenantUuid);
        $matching = array_values(array_filter(
            $guards,
            static fn (array $g): bool => $g['subject_key'] === $subjectKey,
        ));
        self::assertCount(1, $matching, 'exactly one guard row for this subject');
        self::assertSame('live', $matching[0]['state']);

        self::assertSame(
            1,
            $this->connection()->table('subscriptions')
                ->where('tenant_uuid', '=', $tenantUuid)
                ->count(),
            'exactly one reservation must exist -- the losing plan never replaces the winner',
        );
    }

    /** @param array<string,mixed> $args @return array{0:resource,1:array<int,resource>} */
    private function launchRaceChild(array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/workspace_checkout_race_child.php',
                (string) json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /** @param array{0:resource,1:array<int,resource>} $handle @return array<string,mixed> */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}\nstdout: {$stdout}");

        return $result;
    }

    // ==================================================================
    // Harness
    // ==================================================================

    private ?string $lastWorkspaceUuid = null;
    private static int $seq = 0;

    private function key(): string
    {
        return 'wbs-test-key-' . strtolower(substr(Utils::generateNanoID(20), 0, 20));
    }

    private function seedWorkspace(): string
    {
        $uuid = $this->seedTenant();
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $uuid);
        $this->lastWorkspaceUuid = $uuid;

        return $uuid;
    }

    private function currentWorkspaceUuid(): string
    {
        self::assertNotNull($this->lastWorkspaceUuid);

        return $this->lastWorkspaceUuid;
    }

    /** @return array{0:string,1:string} [workspaceUuid, actorUuid] */
    private function seedWorkspaceAndVerifiedActor(?string $email = null): array
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true, email: $email);

        return [$workspace, $actor];
    }

    private function seedTenant(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $uuid;
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'wbs-' . strtolower(substr($uuid, 0, 8)),
            'name' => 'WBS ' . $uuid,
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

    private function seedUser(bool $verified = true, ?string $email = null, bool $softDeleted = false): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->userUuids[] = $uuid;
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'wbs_' . strtolower(substr($uuid, 0, 8)),
            'email' => $email ?? ($uuid . '@example.test'),
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'email_verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($softDeleted) {
            $this->connection()->table('users')->where('uuid', '=', $uuid)->update([
                'deleted_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return $uuid;
    }

    /** A real Aegis grant of the two GLOBAL platform capabilities, never `billing.manage` itself. */
    private function platformOperatorUser(): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;

        $roleSlug = 'wbsmx_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'billing.manage disjointness test role',
            'level' => 90,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach (['tenancy.manage', 'tenancy.access_any'] as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist (seeded by migration 013)");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    /**
     * Returns the LITERAL `$keySuffix` as the plan_key (deterministic, no random suffix) so
     * every test can hardcode `plan_key` in its request body without re-reading this method's
     * return value -- safe because `resetState()` truncates `subscription_plans` every test.
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

    private function enableSelfServe(): void
    {
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
    }

    private function disableSelfServe(): void
    {
        $this->container()->get(SelfServeCheckoutSetting::class)->disable();
    }

    private function defaultGatewayName(): string
    {
        return PayviaSettings::defaultGateway($this->appContext());
    }

    private function payviaTenantUuid(): string
    {
        return $this->container()->get(PayviaTenantResolver::class)->tenantUuid($this->appContext());
    }

    /** The guaranteed non-capable baseline every test starts and ends on (mirrors SelfServeSwitchTest). */
    private function resetGatewayDriver(): void
    {
        $this->container()->get(GatewayManager::class)->registerDriver(
            $this->defaultGatewayName(),
            \Glueful\Extensions\Payvia\Gateways\PaystackGateway::class,
        );
    }

    /** Registers $double as the driver for this environment's ACTUAL configured default gateway. */
    private function registerRecordingGateway(RecordingSubscriptionCheckoutGateway $double): string
    {
        $gatewayName = $this->defaultGatewayName();
        $containerId = RecordingSubscriptionCheckoutGateway::class . ':' . (++self::$seq);

        $container = $this->container();
        self::assertInstanceOf(Container::class, $container);
        $container->load([$containerId => $double]);

        $this->container()->get(GatewayManager::class)->registerDriver($gatewayName, $containerId);

        return $gatewayName;
    }

    private function controller(): SelfBillingController
    {
        $controller = $this->container()->get(SelfBillingController::class);
        self::assertInstanceOf(SelfBillingController::class, $controller);

        return $controller;
    }

    /**
     * A fresh {@see WorkspaceCheckoutCoordinator}, mirroring EXACTLY how `SelfBillingController::
     * checkout()` builds one per request (never container-bound -- see that class's own
     * docblock) -- used by the I2/I5 fix-round tests below to drive `prepare()` directly,
     * bypassing the controller's own pre-checks so the coordinator's own atomicity/determinism
     * guarantees can be proven in isolation.
     */
    private function buildCoordinator(string $gatewayName): WorkspaceCheckoutCoordinator
    {
        return new WorkspaceCheckoutCoordinator(
            $this->container()->get(SubscriptionCheckoutService::class),
            $this->container()->get(CheckoutOriginationRepository::class),
            $this->container()->get(SubscriptionService::class),
        );
    }

    /** @return array{plan_uuid:string,plan_key:string,name:string,provider_identifier:string} */
    private function purchasablePlanRow(string $gateway, string $planKey): array
    {
        foreach (PlanPurchasability::forGateway($this->appContext(), $gateway) as $plan) {
            if ($plan['plan_key'] === $planKey) {
                return $plan;
            }
        }

        self::fail("plan '{$planKey}' is not purchasable for gateway '{$gateway}'");
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

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
