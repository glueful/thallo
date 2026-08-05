<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\NoCancellationCapabilityGateway;
use App\Tests\Support\RecordingSubscriptionLifecycleGateway;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Checkout\PayviaCheckoutGateway;
use Thallo\Subscriptions\Checkout\WorkspaceCheckoutCoordinator;
use Thallo\Subscriptions\Console\CheckoutResolveCommand;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\SelfBillingController;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 17 (Phase C, workspace self-serve checkout plan, spec §5.2/§3.8/§3.7): cancel, abandon, and
 * the operator reconciliation console command --
 * {@see \Thallo\Subscriptions\Http\SelfBillingController::cancel()}/
 * {@see \Thallo\Subscriptions\Http\SelfBillingController::abandon()} +
 * {@see \Thallo\Subscriptions\Console\CheckoutResolveCommand}.
 *
 * Mirrors `WorkspaceBillingSelfServeTest`'s (Task 16) direct-construction idiom: the controller is
 * resolved straight from the container and driven with hand-built requests, never through the full
 * HTTP kernel (see that test's own docblock for why).
 */
final class WorkspaceBillingCancelAbandonTest extends AppTestCase
{
    /** @var list<string> */
    private array $tenantUuids = [];
    /** @var list<string> */
    private array $userUuids = [];

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
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        if ($this->userUuids !== []) {
            $this->connection()->table('users')->whereIn('uuid', $this->userUuids)->forceDelete();
        }
        $this->tenantUuids = [];
        $this->userUuids = [];
        parent::tearDown();
    }

    private function resetState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM subscription_checkout_originations');
        $pdo->exec('DELETE FROM subscription_checkout_subject_guards');
        $pdo->exec('DELETE FROM subscriptions');
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'subscriptions.self_serve_checkout_enabled')
            ->delete();
        $this->connection()->table('thallo_system_flags')
            ->where('key', '=', 'tenancy.default_tenant_uuid')
            ->delete();
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    /** The guaranteed non-capable baseline every test starts and ends on (mirrors Task 16's test). */
    private function resetGatewayDriver(): void
    {
        $this->container()->get(GatewayManager::class)->registerDriver(
            $this->defaultGatewayName(),
            \Glueful\Extensions\Payvia\Gateways\PaystackGateway::class,
        );
    }

    // ==================================================================
    // Cancel: mode validation matrix
    // ==================================================================

    public function testCancelWithStopRenewalModeSucceedsAndCallsProviderCorrectly(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $double = $this->registerLifecycleGateway();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_stripe_001');

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('stop_renewal', $this->data($response)['mode']);

        self::assertCount(1, $double->cancelCalls);
        self::assertSame('sub_stripe_001', $double->cancelCalls[0]['id']);
        self::assertTrue($double->cancelCalls[0]['atPeriodEnd']);
    }

    public function testCancelWithImmediateModePassesAtPeriodEndFalse(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $double = $this->registerLifecycleGateway();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_stripe_002');

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'immediate']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertCount(1, $double->cancelCalls);
        self::assertFalse($double->cancelCalls[0]['atPeriodEnd']);
    }

    public function testCancelRejectsAModeTheDriverDoesNotDeclareWith422(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $double = $this->registerLifecycleGateway();
        $double->modes = ['stop_renewal']; // Paystack-shaped: no 'immediate'.
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_stripe_003');

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'immediate']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_cancellation_mode', $this->errorCode($response));
        self::assertSame(['stop_renewal'], $this->errorDetails($response)['modes'] ?? null);
        self::assertCount(0, $double->cancelCalls, 'a rejected mode must never reach the provider');
    }

    /**
     * Design spec §3.7: "a driver that does not implement the capability exposes no self-serve
     * cancellation modes". A double with NEITHER `SubscriptionCancellationModeProvider` NOR
     * `SubscriptionCheckoutLifecycleCapableGateway` must refuse EVERY mode value 422.
     */
    public function testCancelWithANoInterfaceDriverRejectsAnyModeWith422(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $double = new NoCancellationCapabilityGateway();
        $this->registerDriver($double);
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_no_iface');

        foreach (['stop_renewal', 'immediate', 'anything'] as $mode) {
            $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => $mode]));
            self::assertSame(422, $response->getStatusCode(), "mode '{$mode}' must be refused");
            self::assertSame('invalid_cancellation_mode', $this->errorCode($response));
            self::assertSame([], $this->errorDetails($response)['modes'] ?? null);
        }
        self::assertCount(0, $double->cancelCalls);
    }

    // ==================================================================
    // Cancel: zero subscription-row writes (byte-compare)
    // ==================================================================

    public function testCancelWritesZeroBytesToTheSubscriptionRow(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->registerLifecycleGateway();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_bytecompare');

        $before = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $workspace)
            ->first();
        self::assertNotNull($before);

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $after = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $workspace)
            ->first();
        self::assertSame($before, $after, 'the subscription row must be byte-identical before/after cancel()');
    }

    // ==================================================================
    // Cancel: works with the operator switch OFF (spec §1)
    // ==================================================================

    public function testCancelWorksWithTheSelfServeSwitchOff(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->disableSelfServe();
        $double = $this->registerLifecycleGateway();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_switch_off');

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(1, $double->cancelCalls);
    }

    // ==================================================================
    // Cancel: audit recorded
    // ==================================================================

    public function testCancelDispatchesAnAuditableEventWithActorAndRequestDetails(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->registerLifecycleGateway();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_audit_001');

        $captured = [];
        $listener = static function (object $event) use (&$captured): void {
            $captured[] = $event;
        };
        // Subscribe a lightweight closure listener directly against the SAME shared
        // `ListenerProvider` instance `EventService::dispatch()` resolves listeners from
        // (both bound `shared => true` by `Glueful\Events\ServiceProvider\EventProvider`).
        $this->container()->get(\Glueful\Events\ListenerProvider::class)->addListener(
            \Thallo\Subscriptions\Events\WorkspaceBillingCancellationRequested::class,
            $listener,
        );

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertCount(1, $captured);
        $event = $captured[0];
        self::assertInstanceOf(\Thallo\Subscriptions\Events\WorkspaceBillingCancellationRequested::class, $event);
        self::assertSame($workspace, $event->workspaceUuid);
        self::assertSame($actor, $event->actorUuid);
        self::assertSame('stop_renewal', $event->mode);
        self::assertSame('sub_audit_001', $event->providerSubscriptionId);
        self::assertSame('billing', $event->auditCategory());
        self::assertSame('cancellation_requested', $event->auditAction());
        self::assertSame(['uuid' => $actor], $event->auditActor());
    }

    // ==================================================================
    // Cancel: not_provider_managed
    // ==================================================================

    public function testCancelRefusesWhenThereIsNoSubscriptionAtAll(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->registerLifecycleGateway();

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('not_provider_managed', $this->errorCode($response));
    }

    public function testCancelRefusesWhenTheSubscriptionHasNoProviderSubscriptionId(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->registerLifecycleGateway();
        $this->seedSubscription($workspace, 'incomplete');

        $response = $this->controller()->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('not_provider_managed', $this->errorCode($response));
    }

    // ==================================================================
    // Cancel: engine / payvia degradation
    // ==================================================================

    public function testCancelReturnsStructured409WhenEngineIsUnavailable(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $controller = $this->controllerHidingServices([SubscriptionService::class]);

        $response = $controller->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(EngineGateway::DISABLED, $this->errorCode($response));
    }

    public function testCancelReturnsPayviaUnavailableWhenGatewayManagerIsAbsent(): void
    {
        [$workspace, $actor] = $this->seedWorkspaceAndVerifiedActor();
        $this->seedProviderManagedSubscription($workspace, $this->defaultGatewayName(), 'sub_no_gm');
        $controller = $this->controllerHidingServices([GatewayManager::class]);

        $response = $controller->cancel($this->cancelRequest($actor, ['mode' => 'stop_renewal']));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payvia_unavailable', $this->errorCode($response));
    }

    public function testCancelRequiresAuthentication(): void
    {
        $response = $this->controller()->cancel($this->cancelRequest(null, ['mode' => 'stop_renewal']));
        self::assertSame(401, $response->getStatusCode());
    }

    // ==================================================================
    // Abandon: Stripe confirmed_dead full release chain
    // ==================================================================

    public function testAbandonConfirmedDeadTransitionsOriginationOpensGuardAndReleasesReservation(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'confirmed_dead';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid);

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('abandoned', $this->data($response)['status']);

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('abandoned', $origination['status']);

        $guard = $this->findGuard($workspace);
        self::assertSame('open', $guard['state']);
        self::assertNull($guard['origination_uuid']);

        self::assertSame(
            0,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'the incomplete reservation must be released',
        );
        self::assertSame([$this->referenceFor($originationUuid)], $double->abandonCalls);
    }

    // ==================================================================
    // Abandon: still_live / unsupported / unknown refusals
    // ==================================================================

    public function testAbandonStillLiveRefusesAndLeavesEverythingUntouched(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'still_live';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid);

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('checkout_still_live', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status']);
        self::assertSame('live', $this->findGuard($workspace)['state']);
    }

    public function testAbandonUnknownOutcomeRefusesAsRetryable(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'unknown';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('checkout_abandon_unknown', $this->errorCode($response));
    }

    /** Paystack-shaped: the driver does not implement the lifecycle capability at all. */
    public function testAbandonUnsupportedForADriverWithoutTheLifecycleCapability(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $this->registerDriver(new NoCancellationCapabilityGateway());

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('checkout_abandonment_unsupported', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status'], 'unsupported must never mutate the origination');
    }

    public function testAbandonRefusesWhenThereIsNoLiveCheckoutAtAll(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $this->registerLifecycleGateway();

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('no_live_checkout', $this->errorCode($response));
    }

    // ==================================================================
    // Abandon: checkout_abandon_conflict -- transactional rollback proof (code review Important #2)
    //
    // `RecordingSubscriptionLifecycleGateway::$onAbandonCall` fires right before
    // `abandonSubscriptionCheckout()` returns `confirmed_dead` -- i.e. strictly AFTER
    // `SelfBillingController::abandon()`'s own top-of-method guard/origination reads, and strictly
    // BEFORE `finishAbandon()`'s single transaction runs. This is the only deterministic way, in a
    // single-threaded test, to land a write inside that exact race window.
    // ==================================================================

    /**
     * (a) The guard rebinds to a DIFFERENT origination during the race window -- simulating a
     * concurrent winner (a fresh checkout attempt, or an operator reconciliation) claiming the
     * subject in between. `finishAbandon()`'s transaction still succeeds at releasing the
     * reservation AND at the origination's own `pending -> abandoned` CAS (neither of those two
     * writes know anything about the guard), but its FINAL step -- `guards()->release()`,
     * CAS'd against the EXACT origination it expects to still be bound -- refuses (the guard is
     * now bound to a different uuid). That refusal throws, rolling the whole transaction back:
     * the origination reverts to `pending` and the reservation reappears, proving the three writes
     * commit or fail as one atomic unit.
     */
    public function testAbandonRefusesWithConflictAndRollsBackWhenTheGuardRebindsToADifferentOrigination(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'confirmed_dead';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid);
        // A second origination for the SAME workspace -- `seedOrigination()`'s own `lockAndClaim()`
        // call silently no-ops against it (the guard is already `live` for the FIRST uuid), so it
        // exists purely as the "concurrent winner" this test rebinds the guard to below.
        $otherOriginationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');

        $double->onAbandonCall = function () use ($workspace, $originationUuid, $otherOriginationUuid): void {
            $guards = $this->container()->get(CheckoutSubjectGuardRepository::class);
            $tenantUuid = $this->payviaTenantUuid();
            $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspace);
            self::assertTrue($guards->release($this->appContext(), $tenantUuid, $subjectKey, $originationUuid));
            self::assertTrue(
                $guards->lockAndClaim($this->appContext(), $tenantUuid, $subjectKey, $otherOriginationUuid),
            );
        };

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('checkout_abandon_conflict', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status'], 'the origination transition must roll back');

        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'the reservation release must roll back -- the row must still be present',
        );

        $guard = $this->findGuard($workspace);
        self::assertSame('live', $guard['state']);
        self::assertSame($otherOriginationUuid, $guard['origination_uuid'], 'the rebind itself must survive');
    }

    /**
     * (b) The origination itself moves OFF `pending` during the race window (e.g. a webhook
     * correlating it mid-flight) -- simulating a concurrent event, never something this request
     * caused. `finishAbandon()`'s `transition($uuid, 'pending', 'abandoned')` CAS is therefore
     * refused from the very first write (the row is no longer `pending`), which throws and rolls
     * the reservation release back too -- the origination is never force-marked `abandoned` over
     * whatever it legitimately raced to, and the reservation survives untouched. (Note: unlike
     * scenario (a), the raced-to status here is a real write that happened OUTSIDE
     * `finishAbandon()`'s transaction entirely -- there is nothing for THIS request to roll it
     * back to `pending` FROM, since `transition()` never once succeeds in this path. The atomicity
     * guarantee under test is that the reservation-release side does not get applied without the
     * matching origination write, not that an external actor's write reverts.)
     */
    public function testAbandonRefusesWithConflictAndRollsBackWhenTheOriginationMovesOffPending(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'confirmed_dead';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid);

        $double->onAbandonCall = function () use ($originationUuid): void {
            // Simulates a webhook correlating this origination mid-flight -- a legal `pending ->
            // provider_observed` transition per CheckoutOriginationRepository::TRANSITIONS, done
            // directly (not through this request) so it commits immediately, independent of
            // whatever finishAbandon() does afterward.
            self::assertTrue(
                $this->container()->get(CheckoutOriginationRepository::class)->transition(
                    $this->appContext(),
                    $originationUuid,
                    'pending',
                    'provider_observed',
                ),
            );
        };

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('checkout_abandon_conflict', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame(
            'provider_observed',
            $origination['status'],
            'must never be force-overwritten to abandoned',
        );

        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'the reservation release must roll back -- the row must still be present',
        );
    }

    // ==================================================================
    // Abandon: settled-reservation abort (409, origination NOT abandoned)
    // ==================================================================

    public function testAbandonAbortsWhenTheBoundReservationAlreadySettled(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $double = $this->registerLifecycleGateway();
        $double->abandonOutcome = 'confirmed_dead';

        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        // The reservation ALREADY carries provider fields -- the checkout actually completed.
        $this->seedIncompleteReservation($workspace, $originationUuid, settled: true);

        $response = $this->controller()->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('reservation_settled', $this->errorCode($response));

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status'], 'a settled reservation must abort BEFORE any transition');
        self::assertSame('live', $this->findGuard($workspace)['state'], 'the guard must stay untouched too');

        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'the settled row must survive untouched',
        );
    }

    public function testAbandonRequiresAuthentication(): void
    {
        $response = $this->controller()->abandon($this->abandonRequest(null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAbandonDegradesGracefullyWhenPayviaLedgerIsUnavailable(): void
    {
        $workspace = $this->seedWorkspace();
        $actor = $this->seedUser(verified: true);
        $controller = $this->controllerHidingServices([
            CheckoutOriginationRepository::class,
            CheckoutSubjectGuardRepository::class,
        ]);

        $response = $controller->abandon($this->abandonRequest($actor));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payvia_unavailable', $this->errorCode($response));
    }

    // ==================================================================
    // Console: subscriptions:checkout:resolve
    // ==================================================================

    public function testResolveConfirmedDeadReleasesReservationOpensGuardAndAdvancesToAbandoned(): void
    {
        $workspace = $this->seedWorkspace();
        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid);

        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $exitCode = $tester->execute([
            'origination' => $originationUuid,
            '--resolution' => 'provider_confirmed_dead',
            '--note' => 'Confirmed with the provider dashboard: no charge, no subscription.',
        ], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('abandoned', $origination['status']);
        self::assertSame('provider_confirmed_dead', $origination['reconciliation_resolution']);

        self::assertSame('open', $this->findGuard($workspace)['state']);
        self::assertSame(
            0,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
        );
    }

    public function testResolveCanceledOrRefundedOnProjectionRejectedKeepsStatusAndOpensGuard(): void
    {
        $workspace = $this->seedWorkspace();
        $originationUuid = $this->seedOrigination(
            $workspace,
            $this->defaultGatewayName(),
            'projection_rejected',
            providerSubscriptionId: 'sub_already_observed',
        );
        // Nothing left to release -- this is the "false + no matching row" success path.

        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $exitCode = $tester->execute([
            'origination' => $originationUuid,
            '--resolution' => 'provider_canceled_or_refunded',
            '--note' => 'Refunded via the provider dashboard, ref #99.',
        ], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('projection_rejected', $origination['status'], 'terminal status is kept as history');
        self::assertSame('provider_canceled_or_refunded', $origination['reconciliation_resolution']);
        self::assertSame('open', $this->findGuard($workspace)['state']);
    }

    public function testResolveRefusesAnEmptyNoteWithoutTouchingAnything(): void
    {
        $workspace = $this->seedWorkspace();
        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');

        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $exitCode = $tester->execute([
            'origination' => $originationUuid,
            '--resolution' => 'provider_confirmed_dead',
            '--note' => '   ',
        ], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status']);
    }

    public function testResolveRefusesAnUnknownOrigination(): void
    {
        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $exitCode = $tester->execute([
            'origination' => 'nosuchuuid1',
            '--resolution' => 'provider_confirmed_dead',
            '--note' => 'operator note',
        ], ['interactive' => false]);

        self::assertSame(1, $exitCode);
    }

    /**
     * The reservation-release continuation runs INSIDE Payvia's own owning transaction -- a
     * settled reservation must throw and roll EVERYTHING back together: the origination stays
     * `pending` (never `abandoned`), the guard stays `live` (never reopened), and the settled
     * reservation row survives untouched.
     */
    public function testResolveSettledReservationAbortsTheWholeResolutionAtomically(): void
    {
        $workspace = $this->seedWorkspace();
        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->seedIncompleteReservation($workspace, $originationUuid, settled: true);

        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $exitCode = $tester->execute([
            'origination' => $originationUuid,
            '--resolution' => 'provider_confirmed_dead',
            '--note' => 'trying to claim nothing happened',
        ], ['interactive' => false]);

        self::assertSame(1, $exitCode, $tester->getDisplay());

        $origination = $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)->first();
        self::assertSame('pending', $origination['status'], 'must roll back to pending, never abandoned');
        self::assertNull($origination['reconciliation_resolution']);
        self::assertSame('live', $this->findGuard($workspace)['state'], 'the guard reopen must roll back too');

        self::assertSame(
            1,
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $workspace)->count(),
            'the settled reservation row must survive, untouched, after the rollback',
        );
    }

    public function testResolveCommandIsNotExposedThroughAnyWorkspaceRoute(): void
    {
        foreach (['/v1/admin/billing/resolve', '/v1/admin/billing/checkout/resolve'] as $path) {
            self::assertNull($this->findRoute('POST', $path), "{$path} must not be a registered route");
        }
    }

    public function testResolveCommandOutputPrintsNoProviderPayloadOrPii(): void
    {
        $workspace = $this->seedWorkspace();
        $originationUuid = $this->seedOrigination($workspace, $this->defaultGatewayName(), 'pending');
        $this->connection()->table('subscription_checkout_originations')
            ->where('uuid', '=', $originationUuid)
            ->update(['customer_email' => 'operator-should-not-see@example.test']);

        $tester = new CommandTester(new CheckoutResolveCommand($this->container(), $this->appContext()));
        $tester->execute([
            'origination' => $originationUuid,
            '--resolution' => 'provider_confirmed_dead',
            '--note' => 'operator note',
        ], ['interactive' => false]);

        self::assertStringNotContainsString('operator-should-not-see@example.test', $tester->getDisplay());
    }

    // ==================================================================
    // Harness
    // ==================================================================

    private function seedWorkspace(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $uuid;
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'wca-' . strtolower(substr($uuid, 0, 8)),
            'name' => 'WCA ' . $uuid,
            'status' => 'active',
        ]);
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $uuid);

        return $uuid;
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
            'username' => 'wca_' . strtolower(substr($uuid, 0, 8)),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'email_verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    private function seedSubscription(string $tenantUuid, string $status): void
    {
        $this->connection()->table('subscriptions')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => 'tenant',
            'subject_uuid' => $tenantUuid,
            'plan_uuid' => Utils::generateNanoID(12),
            'plan_key' => 'seeded-plan',
            'status' => $status,
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

    /**
     * Directly seeds a `pending` (or `$status`) origination + a `live` subject guard bound to it
     * -- mirrors `CheckoutReconciliationTest`'s own raw-row seeding style, avoiding the need for a
     * full `SubscriptionInitiationCapableGateway` double just to drive abandon()/resolve().
     */
    private function seedOrigination(
        string $workspaceUuid,
        string $gateway,
        string $status,
        ?string $providerSubscriptionId = null,
    ): string {
        $originationUuid = Utils::generateNanoID(12);
        $tenantUuid = $this->payviaTenantUuid();
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspaceUuid);

        $this->connection()->table('subscription_checkout_originations')->insert([
            'uuid' => $originationUuid,
            'tenant_uuid' => $tenantUuid,
            'subject_key' => $subjectKey,
            'gateway' => $gateway,
            'provider_plan_identifier' => 'price_test',
            'idempotency_key' => 'wca-key-' . $originationUuid,
            'request_fingerprint' => str_repeat('a', 64),
            'return_url' => 'https://admin.test/billing/return',
            'cancel_url' => 'https://admin.test/billing/return',
            'checkout_reference' => $this->referenceFor($originationUuid),
            'checkout_url' => 'https://checkout.test/' . $originationUuid,
            'provider_subscription_id' => $providerSubscriptionId,
            // design spec §3.4's enrichment fields -- `CheckoutResolveCommand` reads
            // `tenant_uuid` back out of here (NEVER the ledger-scope `tenant_uuid` column above)
            // to know which workspace's reservation to release.
            'consumer_metadata' => json_encode([
                'tenant_uuid' => $workspaceUuid,
                'subject_type' => 'tenant',
                'subject_uuid' => $workspaceUuid,
            ]),
            'status' => $status,
            'live' => !in_array(
                $status,
                ['dispatched', 'failed', 'expired', 'abandoned', 'projection_rejected', 'late_settlement_conflict'],
                true,
            ),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->container()->get(CheckoutSubjectGuardRepository::class)->lockAndClaim(
            $this->appContext(),
            $tenantUuid,
            $subjectKey,
            $originationUuid,
        );

        if (in_array($status, ['projection_rejected', 'late_settlement_conflict'], true)) {
            $this->container()->get(CheckoutSubjectGuardRepository::class)->block(
                $this->appContext(),
                $tenantUuid,
                $subjectKey,
                $originationUuid,
                $status,
            );
        }

        return $originationUuid;
    }

    private function referenceFor(string $originationUuid): string
    {
        return 'cs_test_' . $originationUuid;
    }

    private function seedIncompleteReservation(
        string $workspaceUuid,
        string $originationUuid,
        bool $settled = false,
    ): void {
        $this->connection()->table('subscriptions')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $workspaceUuid,
            'subject_type' => 'tenant',
            'subject_uuid' => $workspaceUuid,
            'plan_uuid' => Utils::generateNanoID(12),
            'plan_key' => 'seeded-plan',
            'status' => $settled ? 'active' : 'incomplete',
            'checkout_origination_uuid' => $originationUuid,
            'provider_gateway' => $settled ? $this->defaultGatewayName() : null,
            'provider_subscription_id' => $settled ? 'sub_settled_001' : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function findGuard(string $workspaceUuid): ?array
    {
        return $this->connection()->table('subscription_checkout_subject_guards')
            ->where('tenant_uuid', '=', $this->payviaTenantUuid())
            ->where('subject_key', '=', WorkspaceCheckoutCoordinator::subjectKey($workspaceUuid))
            ->first();
    }

    private function defaultGatewayName(): string
    {
        return PayviaSettings::defaultGateway($this->appContext());
    }

    private function payviaTenantUuid(): string
    {
        return $this->container()->get(PayviaTenantResolver::class)->tenantUuid($this->appContext());
    }

    private function enableSelfServe(): void
    {
        $this->container()->get(SelfServeCheckoutSetting::class)->enable();
    }

    private function disableSelfServe(): void
    {
        $this->container()->get(SelfServeCheckoutSetting::class)->disable();
    }

    private function registerLifecycleGateway(): RecordingSubscriptionLifecycleGateway
    {
        $double = new RecordingSubscriptionLifecycleGateway();
        $this->registerDriver($double);

        return $double;
    }

    private function registerDriver(object $double): void
    {
        $gatewayName = $this->defaultGatewayName();
        static $seq = 0;
        $containerId = $double::class . ':' . (++$seq);

        $container = $this->container();
        self::assertInstanceOf(Container::class, $container);
        $container->load([$containerId => $double]);

        $this->container()->get(GatewayManager::class)->registerDriver($gatewayName, $containerId);
    }

    private function controller(): SelfBillingController
    {
        $controller = $this->container()->get(SelfBillingController::class);
        self::assertInstanceOf(SelfBillingController::class, $controller);

        return $controller;
    }

    /**
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
     * @param list<string> $hiddenIds
     *
     * Builds BOTH `EngineGateway` and `PayviaCheckoutGateway` fresh, from the SAME
     * hidden-services context -- unlike `WorkspaceBillingSelfServeTest`'s own
     * `controllerHidingServices()` (Task 16), which only ever hides Payvia/users services and
     * so can safely reuse the REAL container-resolved `EngineGateway`. Here `SubscriptionService`
     * itself is sometimes the hidden id (to simulate the engine being disabled), and
     * `EngineGateway::engineState()` probes `$this->context->getContainer()` -- a container-
     * resolved `EngineGateway` would still hold the REAL context and never see the hidden id.
     */
    private function controllerHidingServices(array $hiddenIds): SelfBillingController
    {
        $context = $this->contextHidingServices($hiddenIds);

        return new SelfBillingController(
            $context,
            new EngineGateway($context),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SelfServeCheckoutSetting::class),
            $this->container()->get(SelfServeGatewayCapability::class),
            new PayviaCheckoutGateway($context),
        );
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
