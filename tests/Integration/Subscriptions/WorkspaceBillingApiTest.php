<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\CountingPdoStatement;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\MetaController;
use Thallo\Subscriptions\Http\WorkspaceBillingController;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 9 (Phase B): the workspace billing admin API + meta --
 * `/v1/admin/subscriptions/{meta,workspaces*}`. Mirrors Task 8's PlansAdminApiTest conventions
 * (real-kernel API-key auth, 403 route loop, direct-construct stub-gateway 409s) and extends them
 * with this task's own concerns: the provider-managed guard, the two tenancy-mode degradations
 * (single-store `default_workspace_missing`, tenancy-on directory pagination with NO
 * caller-supplied UUID filter), and cross-workspace override read/write correctness.
 */
final class WorkspaceBillingApiTest extends AppTestCase
{
    private const BASE = '/v1/admin/subscriptions';

    /** @var list<array{0:string,1:string}> [method, path] pairs THIS task registers. */
    private const ROUTES = [
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSubscriptionsAndTenancyState();
    }

    protected function tearDown(): void
    {
        $this->resetSubscriptionsAndTenancyState();
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

    private function resetSubscriptionsAndTenancyState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM subscription_overrides');
        $pdo->exec('DELETE FROM subscriptions');
        $pdo->exec('DELETE FROM subscription_plans');
        $pdo->exec('TRUNCATE TABLE tenant_memberships, tenants, users CASCADE');
        $this->container()->get(SystemFlags::class)->clearCache();
    }

    // ==================================================================
    // Structural pin + 403 posture loop (mirrors Task 8's PlansAdminApiTest)
    // ==================================================================

    public function testEveryRouteIsRegisteredWithItsNameAndTheExactGroupMiddleware(): void
    {
        $expectedMiddleware = ['auth', 'tenant_system', 'content_permission:tenancy.manage'];
        $expectedNames = [
            'GET:' . self::BASE . '/meta' => 'thallo.subscriptions.admin.meta',
            'GET:' . self::BASE . '/workspaces' => 'thallo.subscriptions.admin.workspaces.index',
            'GET:' . self::BASE . '/workspaces/{uuid}' => 'thallo.subscriptions.admin.workspaces.show',
            'PUT:' . self::BASE . '/workspaces/{uuid}/plan' => 'thallo.subscriptions.admin.workspaces.plan',
            'POST:' . self::BASE . '/workspaces/{uuid}/cancel' => 'thallo.subscriptions.admin.workspaces.cancel',
            'PUT:' . self::BASE . '/workspaces/{uuid}/overrides/{entitlement}'
                => 'thallo.subscriptions.admin.workspaces.overrides.upsert',
            'DELETE:' . self::BASE . '/workspaces/{uuid}/overrides/{entitlement}'
                => 'thallo.subscriptions.admin.workspaces.overrides.delete',
        ];

        foreach (self::ROUTES as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            self::assertSame($expectedNames["{$method}:{$path}"], $route['name']);
            foreach ($expectedMiddleware as $middleware) {
                self::assertContains($middleware, (array) $route['middleware'], "{$method} {$path}");
            }
        }
    }

    public function testEveryRouteRejectsANonTenancyManageActorWith403(): void
    {
        $key = $this->seedApiKeyUser([], []);

        foreach (self::ROUTES as [$method, $path]) {
            $requestPath = str_replace(['{uuid}', '{entitlement}'], ['irrelevant-uuid', 'irrelevant-ent'], $path);
            $response = $this->handle($this->apiKeyRequest($method, $requestPath, $key));
            self::assertSame(
                403,
                $response->getStatusCode(),
                "{$method} {$requestPath} must reject a non-tenancy.manage actor",
            );
        }
    }

    // ==================================================================
    // /meta: 200 always, every engine state, both tenancy modes
    // ==================================================================

    public function testMetaReturns200InEveryEngineStateAndTenancyMode(): void
    {
        foreach ([false, true] as $tenancyEnabled) {
            if ($tenancyEnabled) {
                $this->enableTenancy();
            }

            // ready
            $ready = new MetaController(
                $this->container()->get(EngineGateway::class),
                $this->container()->get(SystemFlags::class),
                $this->container()->get(SingleStoreTenant::class),
            );
            $response = $ready->show(Request::create('/', 'GET'));
            self::assertSame(200, $response->getStatusCode());
            $body = $this->data($response);
            self::assertSame(EngineGateway::READY, $body['engine']);
            self::assertSame($tenancyEnabled, $body['tenancy_enabled']);

            // disabled
            $disabled = new MetaController(
                $this->disabledGateway(),
                $this->container()->get(SystemFlags::class),
                $this->container()->get(SingleStoreTenant::class),
            );
            $response = $disabled->show(Request::create('/', 'GET'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(EngineGateway::DISABLED, $this->data($response)['engine']);

            // schema not ready
            $schemaNotReady = new MetaController(
                new EngineGateway($this->contextWithStubbedReadiness(false)),
                $this->container()->get(SystemFlags::class),
                $this->container()->get(SingleStoreTenant::class),
            );
            $response = $schemaNotReady->show(Request::create('/', 'GET'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(EngineGateway::SCHEMA_NOT_READY, $this->data($response)['engine']);

            $this->resetSubscriptionsAndTenancyState();
        }
    }

    public function testMetaStaysTwoHundredWithNullDefaultWhileSingleStoreWorkspaceRoutesReturn409(): void
    {
        // Tenancy OFF, no default established at all (fresh-install shape).
        $meta = $this->container()->get(MetaController::class);
        $response = $meta->show(Request::create('/', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->data($response);
        self::assertFalse($body['tenancy_enabled']);
        self::assertNull($body['default_tenant_uuid']);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $index = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces', $key));
        self::assertSame(409, $index->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($index));

        $show = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces/anything', $key));
        self::assertSame(409, $show->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($show));

        $setPlan = $this->handle($this->apiKeyRequest('PUT', self::BASE . '/workspaces/anything/plan', $key, [
            'plan_key' => 'free',
        ]));
        self::assertSame(409, $setPlan->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($setPlan));

        $cancel = $this->handle($this->apiKeyRequest('POST', self::BASE . '/workspaces/anything/cancel', $key));
        self::assertSame(409, $cancel->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($cancel));

        $upsert = $this->handle(
            $this->apiKeyRequest('PUT', self::BASE . '/workspaces/anything/overrides/widgets.limit', $key, [
                'value' => 5,
            ]),
        );
        self::assertSame(409, $upsert->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($upsert));

        $delete = $this->handle(
            $this->apiKeyRequest('DELETE', self::BASE . '/workspaces/anything/overrides/widgets.limit', $key),
        );
        self::assertSame(409, $delete->getStatusCode());
        self::assertSame('default_workspace_missing', $this->errorCode($delete));
    }

    // ==================================================================
    // Index: directory order, 100-row batch clamp, no caller UUID filter,
    // constant query count regardless of page/directory size.
    // ==================================================================

    public function testWorkspaceIndexPreservesDirectoryOrderClampsBatchAndIgnoresCallerUuidsParam(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('bulk-plan');

        // 101 tenants, deterministic zero-padded uuids so directory order (created_at asc,
        // uuid asc) matches insertion/expected order regardless of timestamp ties.
        $uuids = [];
        for ($i = 1; $i <= 101; $i++) {
            $uuid = sprintf('wsb%09d', $i);
            $uuids[] = $uuid;
            $this->connection()->table('tenants')->insert([
                'uuid' => $uuid,
                'slug' => 'wsb-' . $i,
                'name' => 'WSB ' . $i,
                'status' => 'active',
            ]);
        }
        // Only the first tenant has a subscription -- proves absent-subscription rows too.
        $this->startSubscription($uuids[0], $planKey);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        // A caller-supplied `?uuids=` filter must be completely ignored.
        $page1 = $this->handle($this->apiKeyRequest(
            'GET',
            self::BASE . '/workspaces?page=1&per_page=100&uuids[]=should-be-ignored',
            $key,
        ));
        self::assertSame(200, $page1->getStatusCode(), (string) $page1->getContent());
        $body1 = $this->fullBody($page1);
        self::assertCount(100, $body1['data']);
        self::assertSame(101, $body1['total']);
        self::assertSame(array_slice($uuids, 0, 100), array_column(array_column($body1['data'], 'tenant'), 'uuid'));

        $page2 = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?page=2&per_page=100', $key));
        self::assertSame(200, $page2->getStatusCode());
        $body2 = $this->fullBody($page2);
        self::assertCount(1, $body2['data']);
        self::assertSame([$uuids[100]], array_column(array_column($body2['data'], 'tenant'), 'uuid'));

        // Constant query-count proof (mirrors ShopCatalogTest's established idiom): a
        // per-row N+1 would make page 1 (100 joined rows) cost more queries than page 2 (1 row).
        $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        try {
            self::assertSame(
                200,
                $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?page=1&per_page=100', $key))
                    ->getStatusCode(),
            );
            $before = CountingPdoStatement::$count;
            self::assertSame(
                200,
                $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?page=1&per_page=100', $key))
                    ->getStatusCode(),
            );
            $hundredRowQueries = CountingPdoStatement::$count - $before;

            $before = CountingPdoStatement::$count;
            self::assertSame(
                200,
                $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?page=2&per_page=100', $key))
                    ->getStatusCode(),
            );
            $oneRowQueries = CountingPdoStatement::$count - $before;

            self::assertSame(
                $hundredRowQueries,
                $oneRowQueries,
                'workspace index query count must be constant in page row count '
                . "(100 rows: {$hundredRowQueries} queries, 1 row: {$oneRowQueries} queries)",
            );
        } finally {
            $this->connection()->getPDO()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
    }

    public function testWorkspaceIndexJoinsAllRequestedWorkspacesWithAForeignActiveTenantAmbient(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('foreign-ctx-plan');
        $tenantA = $this->seedTenant('foreign-a');
        $tenantB = $this->seedTenant('foreign-b');
        $foreign = $this->seedTenant('foreign-observer');
        $this->startSubscription($tenantA, $planKey);
        $this->startSubscription($tenantB, $planKey);

        $controller = $this->container()->get(WorkspaceBillingController::class);

        // Drive the controller directly (bypassing the HTTP/auth pipeline, which is not what
        // this test is about) with a REAL foreign tenant ambient via the tenancy extension's own
        // Bypass\Tenancy -- proving the engine's cross-workspace bulk read is not narrowed by
        // whatever tenant happens to be active on this process (a concurrent request, a job)
        // when the administrative page is built.
        CurrentContext::set($this->appContext());
        try {
            $response = Tenancy::runAsTenant(
                $foreign,
                fn () => $controller->index(Request::create('/?per_page=100', 'GET')),
            );
        } finally {
            CurrentContext::clear();
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->fullBody($response);
        $uuidsSeen = array_column(array_column($body['data'], 'tenant'), 'uuid');
        self::assertContains($tenantA, $uuidsSeen);
        self::assertContains($tenantB, $uuidsSeen);
        self::assertContains($foreign, $uuidsSeen);

        $subscriptionsByUuid = [];
        foreach ($body['data'] as $row) {
            $subscriptionsByUuid[$row['tenant']['uuid']] = $row['subscription'];
        }
        self::assertNotNull($subscriptionsByUuid[$tenantA], 'tenant A subscription must not be narrowed away');
        self::assertNotNull($subscriptionsByUuid[$tenantB], 'tenant B subscription must not be narrowed away');
    }

    public function testWorkspaceIndexRowsReportAbsentSubscriptionAndProviderManagedFlag(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('flag-plan');
        $noSub = $this->seedTenant('flag-none');
        $manual = $this->seedTenant('flag-manual');
        $providerLinked = $this->seedTenant('flag-provider');

        $this->startSubscription($manual, $planKey);
        $this->startSubscription($providerLinked, $planKey, [
            'provider_gateway' => 'stripe',
            'provider_customer_id' => 'cus_flagtest',
            'provider_subscription_id' => 'sub_flagtest',
        ]);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $response = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?per_page=100', $key));
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->fullBody($response)['data'];
        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[$row['tenant']['uuid']] = $row;
        }

        self::assertNull($byUuid[$noSub]['subscription']);
        self::assertNotNull($byUuid[$manual]['subscription']);
        self::assertFalse($byUuid[$manual]['subscription']['provider_managed']);
        self::assertSame($planKey, $byUuid[$manual]['subscription']['plan_key']);
        self::assertNotNull($byUuid[$providerLinked]['subscription']);
        self::assertTrue($byUuid[$providerLinked]['subscription']['provider_managed']);
    }

    // ==================================================================
    // Detail + overrides round-trip (active + expired, expires_at/reason)
    // ==================================================================

    public function testWorkspaceDetailRoundTripsActiveAndExpiredOverridesWithExpiryAndReason(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('detail-plan');
        $tenant = $this->seedTenant('detail-tenant');
        $this->startSubscription($tenant, $planKey);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $past = gmdate('Y-m-d H:i:s', time() - 86400);

        $active = $this->handle($this->apiKeyRequest(
            'PUT',
            self::BASE . "/workspaces/{$tenant}/overrides/widgets.limit",
            $key,
            ['value' => 42, 'expires_at' => $future, 'reason' => 'promo'],
        ));
        self::assertSame(200, $active->getStatusCode(), (string) $active->getContent());

        $expired = $this->handle($this->apiKeyRequest(
            'PUT',
            self::BASE . "/workspaces/{$tenant}/overrides/reports.enabled",
            $key,
            ['value' => true, 'expires_at' => $past, 'reason' => 'trial ended'],
        ));
        self::assertSame(200, $expired->getStatusCode(), (string) $expired->getContent());

        $show = $this->handle($this->apiKeyRequest('GET', self::BASE . "/workspaces/{$tenant}", $key));
        self::assertSame(200, $show->getStatusCode());
        $body = $this->data($show);
        self::assertSame($tenant, $body['tenant']['uuid']);
        self::assertSame($planKey, $body['subscription']['plan_key']);

        $overridesByEntitlement = [];
        foreach ($body['overrides'] as $row) {
            $overridesByEntitlement[$row['entitlement']] = $row;
        }

        self::assertSame(42, $overridesByEntitlement['widgets.limit']['value']);
        self::assertSame('promo', $overridesByEntitlement['widgets.limit']['reason']);
        self::assertNotNull($overridesByEntitlement['widgets.limit']['expires_at']);

        self::assertTrue($overridesByEntitlement['reports.enabled']['value']);
        self::assertSame('trial ended', $overridesByEntitlement['reports.enabled']['reason']);
        self::assertNotNull($overridesByEntitlement['reports.enabled']['expires_at']);

        // Delete the active one, confirm it disappears while the (still-listed, expired) sibling
        // key remains addressable independently.
        $delete = $this->handle(
            $this->apiKeyRequest('DELETE', self::BASE . "/workspaces/{$tenant}/overrides/widgets.limit", $key),
        );
        self::assertSame(200, $delete->getStatusCode());

        $showAgain = $this->handle($this->apiKeyRequest('GET', self::BASE . "/workspaces/{$tenant}", $key));
        $entitlements = array_column($this->data($showAgain)['overrides'], 'entitlement');
        self::assertNotContains('widgets.limit', $entitlements);
        self::assertContains('reports.enabled', $entitlements);
    }

    // ==================================================================
    // Recording runner: override list/upsert/delete enter the TARGET workspace,
    // sibling data stays untouched, entitlement resolver honors it.
    // ==================================================================

    public function testRecordingRunnerRoutesOverridesToTargetWorkspaceAndEntitlementResolverHonorsIt(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('runner-plan');
        $target = $this->seedTenant('runner-target');
        $sibling = $this->seedTenant('runner-sibling');
        $this->startSubscription($target, $planKey);
        $this->startSubscription($sibling, $planKey);

        // Sibling has its own pre-existing override -- must remain untouched by everything
        // this test does to $target.
        $this->container()->get(\Glueful\Extensions\Subscriptions\Repositories\OverrideRepository::class)
            ->upsertForSubject(
                $this->appContext(),
                \Glueful\Extensions\Subscriptions\Subject::tenant($sibling),
                'widgets.limit',
                7,
            );

        $runner = new class () implements TenantContextRunner {
            /** @var list<array{mode:string,tenantUuid:?string}> */
            private array $calls = [];

            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                $this->calls[] = ['mode' => 'tenant', 'tenantUuid' => $tenantUuid];

                return $fn();
            }

            public function runAsSystem(callable $fn): mixed
            {
                $this->calls[] = ['mode' => 'system', 'tenantUuid' => null];

                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
                throw new \RuntimeException('not exercised');
            }

            /** @return list<array{mode:string,tenantUuid:?string}> */
            public function calls(): array
            {
                return $this->calls;
            }
        };

        $controller = $this->workspaceControllerWithRunner($runner);

        $upsert = $controller->upsertOverride(
            Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['value' => 99])),
            $target,
            'widgets.limit',
        );
        self::assertSame(200, $upsert->getStatusCode());

        $show = $controller->show(Request::create('/', 'GET'), $target);
        self::assertSame(200, $show->getStatusCode());

        $delete = $controller->deleteOverride(Request::create('/', 'DELETE'), $target, 'widgets.limit');
        self::assertSame(200, $delete->getStatusCode());

        // Exactly one runAsTenantOr wrap per action -- upsert, show (listForSubject), delete, in
        // that call order. A dropped wrap on any ONE of the three would shrink this to 2, which
        // `assertNotSame([], ...)` could never catch (the other two still populate calls() and
        // show() still 200s regardless, since the repository's WHERE already pins the subject).
        $calls = $runner->calls();
        self::assertCount(3, $calls, 'expected exactly one runAsTenantOr call each for upsert/show/delete');
        self::assertSame(['tenant', $target], [$calls[0]['mode'], $calls[0]['tenantUuid']], 'call 1: upsertOverride');
        self::assertSame(['tenant', $target], [$calls[1]['mode'], $calls[1]['tenantUuid']], 'call 2: show');
        self::assertSame(['tenant', $target], [$calls[2]['mode'], $calls[2]['tenantUuid']], 'call 3: deleteOverride');

        // Sibling's own override must be completely untouched by anything done to $target.
        $siblingOverrides = $this->container()
            ->get(\Glueful\Extensions\Subscriptions\Repositories\OverrideRepository::class)
            ->listForSubject($this->appContext(), \Glueful\Extensions\Subscriptions\Subject::tenant($sibling));
        self::assertCount(1, $siblingOverrides);
        self::assertSame('widgets.limit', $siblingOverrides[0]['entitlement']);
        self::assertSame(7, $siblingOverrides[0]['value']);

        // The entitlement resolver (the REAL app path, not our recorder) must honor an active
        // override written through the controller.
        $reUpsert = $controller->upsertOverride(
            Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['value' => 123])),
            $target,
            'widgets.limit',
        );
        self::assertSame(200, $reUpsert->getStatusCode());

        $map = $this->container()->get(EntitlementResolver::class)->resolveMap($this->appContext(), $target);
        self::assertSame(123, $map['widgets.limit']);
    }

    // ==================================================================
    // Set-plan: start vs changePlan, provider-managed guard
    // ==================================================================

    public function testSetPlanStartsFreshSubscriptionAndChangesAnExistingOne(): void
    {
        $this->enableTenancy();
        $planA = $this->seedPlan('setplan-a');
        $planB = $this->seedPlan('setplan-b');
        $tenant = $this->seedTenant('setplan-tenant');
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $start = $this->handle($this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$tenant}/plan", $key, [
            'plan_key' => $planA,
        ]));
        self::assertSame(200, $start->getStatusCode(), (string) $start->getContent());
        self::assertSame($planA, $this->data($start)['plan_key']);

        $change = $this->handle($this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$tenant}/plan", $key, [
            'plan_key' => $planB,
        ]));
        self::assertSame(200, $change->getStatusCode(), (string) $change->getContent());
        self::assertSame($planB, $this->data($change)['plan_key']);
    }

    public function testProviderManagedSubscriptionRefusesPlanChangeAndCancelWhileManualSucceeds(): void
    {
        $this->enableTenancy();
        $planA = $this->seedPlan('guard-a');
        $planB = $this->seedPlan('guard-b');
        $manual = $this->seedTenant('guard-manual');
        $providerLinked = $this->seedTenant('guard-provider');

        $this->startSubscription($manual, $planA);
        $this->startSubscription($providerLinked, $planA, [
            'provider_gateway' => 'stripe',
            'provider_customer_id' => 'cus_guardtest',
            'provider_subscription_id' => 'sub_guardtest',
        ]);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        // Manual: both actions succeed.
        $manualPlan = $this->handle($this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$manual}/plan", $key, [
            'plan_key' => $planB,
        ]));
        self::assertSame(200, $manualPlan->getStatusCode(), (string) $manualPlan->getContent());

        $manualCancel = $this->handle($this->apiKeyRequest('POST', self::BASE . "/workspaces/{$manual}/cancel", $key));
        self::assertSame(200, $manualCancel->getStatusCode(), (string) $manualCancel->getContent());

        // Provider-linked: both actions refuse with the structured 409.
        $providerPlan = $this->handle(
            $this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$providerLinked}/plan", $key, [
                'plan_key' => $planB,
            ]),
        );
        self::assertSame(409, $providerPlan->getStatusCode());
        self::assertSame('provider_managed_subscription', $this->errorCode($providerPlan));

        $providerCancel = $this->handle(
            $this->apiKeyRequest('POST', self::BASE . "/workspaces/{$providerLinked}/cancel", $key),
        );
        self::assertSame(409, $providerCancel->getStatusCode());
        self::assertSame('provider_managed_subscription', $this->errorCode($providerCancel));
    }

    public function testCancelOnAWorkspaceWithNoSubscriptionReturns422(): void
    {
        $this->enableTenancy();
        $tenant = $this->seedTenant('cancel-no-subscription');
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $response = $this->handle($this->apiKeyRequest('POST', self::BASE . "/workspaces/{$tenant}/cancel", $key));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('workspace has no subscription to cancel', (string) $response->getContent());
    }

    // ==================================================================
    // 404 unknown workspace, 422 invalid pagination
    // ==================================================================

    public function testUnknownWorkspaceReturns404OnEveryWorkspaceScopedRoute(): void
    {
        $this->enableTenancy();
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $unknown = Utils::generateNanoID();

        $show = $this->handle($this->apiKeyRequest('GET', self::BASE . "/workspaces/{$unknown}", $key));
        self::assertSame(404, $show->getStatusCode());

        $plan = $this->handle($this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$unknown}/plan", $key, [
            'plan_key' => 'free',
        ]));
        self::assertSame(404, $plan->getStatusCode());

        $cancel = $this->handle($this->apiKeyRequest('POST', self::BASE . "/workspaces/{$unknown}/cancel", $key));
        self::assertSame(404, $cancel->getStatusCode());

        $upsert = $this->handle(
            $this->apiKeyRequest('PUT', self::BASE . "/workspaces/{$unknown}/overrides/widgets.limit", $key, [
                'value' => 1,
            ]),
        );
        self::assertSame(404, $upsert->getStatusCode());

        $delete = $this->handle(
            $this->apiKeyRequest('DELETE', self::BASE . "/workspaces/{$unknown}/overrides/widgets.limit", $key),
        );
        self::assertSame(404, $delete->getStatusCode());
    }

    public function testInvalidPaginationReturns422(): void
    {
        $this->enableTenancy();
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        foreach (['page=0', 'page=abc', 'per_page=0', 'per_page=abc', 'page=-1'] as $query) {
            $response = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?' . $query, $key));
            self::assertSame(422, $response->getStatusCode(), "?{$query} must be rejected");
        }

        // per_page above the ceiling is CLAMPED, not rejected.
        $tenant = $this->seedTenant('pagination-clamp');
        $clamped = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?per_page=500', $key));
        self::assertSame(200, $clamped->getStatusCode());
        self::assertSame(100, $this->fullBody($clamped)['per_page']);
    }

    // ==================================================================
    // Engine disabled: structured 409 on every workspace action (direct construct)
    // ==================================================================

    public function testEngineDisabledReturns409WithStructuredCodeOnEveryWorkspaceAction(): void
    {
        $tenant = $this->seedTenant('disabled-tenant');
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $tenant);

        $controller = new WorkspaceBillingController(
            $this->appContext(),
            $this->disabledGateway(),
            $this->container()->get(TenantAdministration::class),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SystemFlags::class),
        );

        $cases = [
            'index' => fn () => $controller->index(Request::create('/', 'GET')),
            'show' => fn () => $controller->show(Request::create('/', 'GET'), $tenant),
            'setPlan' => fn () => $controller->setPlan(
                Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['plan_key' => 'free'])),
                $tenant,
            ),
            'cancel' => fn () => $controller->cancel(Request::create('/', 'POST'), $tenant),
            'upsertOverride' => fn () => $controller->upsertOverride(
                Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['value' => 1])),
                $tenant,
                'widgets.limit',
            ),
            'deleteOverride' => fn () => $controller->deleteOverride(
                Request::create('/', 'DELETE'),
                $tenant,
                'widgets.limit',
            ),
        ];

        foreach ($cases as $action => $call) {
            $response = $call();
            self::assertSame(409, $response->getStatusCode(), $action);
            self::assertSame('engine_disabled', $this->errorCode($response), $action);
        }
    }

    // ==================================================================
    // Final-wave fix B: ONE readiness probe per ACTION, not one per accessor
    // ==================================================================

    /**
     * `SubscriptionSchemaReadiness::isReady()` is 5 `hasTable()` + 27 `hasColumn()` uncached
     * introspection queries, and {@see EngineGateway} (rightly) never caches its verdict across
     * calls. Before this fix EVERY accessor re-probed, so `show()` -- which needs
     * `subscriptions()` + `overrides()` + `plans()` -- paid it THREE times (~96 introspection
     * queries for one request) and `index()` twice. The probe is counted directly, at the seam
     * that performs it, rather than inferred from a total query count.
     */
    public function testEachControllerActionProbesSchemaReadinessExactlyOnce(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('probe-plan');
        $tenant = $this->seedTenant('probe');
        $this->startSubscription($tenant, $planKey);

        $expectations = [
            // action => probes; show() resolves THREE engine services, index() two, the rest one.
            'show' => fn (WorkspaceBillingController $c) => $c->show(Request::create('/', 'GET'), $tenant),
            'index' => fn (WorkspaceBillingController $c) => $c->index(Request::create('/', 'GET')),
            'setPlan' => fn (WorkspaceBillingController $c) => $c->setPlan(
                Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['plan_key' => $planKey])),
                $tenant,
            ),
            'cancel' => fn (WorkspaceBillingController $c) => $c->cancel(
                Request::create('/', 'POST', [], [], [], [], (string) json_encode(['at_period_end' => true])),
                $tenant,
            ),
            'upsertOverride' => fn (WorkspaceBillingController $c) => $c->upsertOverride(
                Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['value' => 5])),
                $tenant,
                'widgets.limit',
            ),
            'deleteOverride' => fn (WorkspaceBillingController $c) => $c->deleteOverride(
                Request::create('/', 'DELETE'),
                $tenant,
                'widgets.limit',
            ),
        ];

        foreach ($expectations as $action => $call) {
            $counter = $this->countingReadinessProbe();
            $response = $call($this->workspaceControllerWithReadinessProbe($counter));

            self::assertLessThan(400, $response->getStatusCode(), "{$action}: " . (string) $response->getContent());
            self::assertSame(
                1,
                $counter->calls,
                "{$action}() must resolve the engine state exactly ONCE per action "
                . "(saw {$counter->calls} full schema-readiness probes)",
            );
        }
    }

    // ==================================================================
    // Final-wave fix C: directory visibility vs detail, and non-active writes
    // ==================================================================

    /**
     * `listTenants()` is raw SQL with NO soft-delete filter while `getTenant()` goes through the
     * ORM's soft-delete scope: a soft-deleted workspace used to be LISTED in the directory and then
     * 404 the moment an operator clicked it. Live-but-not-`active` workspaces stay listed (with
     * their status, which the SPA renders) -- billing WRITES against them are refused separately.
     */
    public function testDirectoryExcludesSoftDeletedWorkspacesButKeepsSuspendedOnesVisible(): void
    {
        $this->enableTenancy();
        $active = $this->seedTenant('vis-active');
        $suspended = $this->seedTenant('vis-suspended', 'suspended');
        $provisioning = $this->seedTenant('vis-provisioning', 'provisioning');
        $deleted = $this->seedTenant('vis-deleted', 'deleted', softDeleted: true);

        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $response = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?per_page=100', $key));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $body = $this->fullBody($response);
        $listed = array_column(array_column($body['data'], 'tenant'), 'uuid');
        sort($listed);
        $expected = [$active, $suspended, $provisioning];
        sort($expected);
        self::assertSame($expected, $listed, 'the directory must list exactly the non-soft-deleted workspaces');
        self::assertSame(3, $body['total'], 'total must count the FILTERED directory, not the raw table');

        // Every listed row carries its own lifecycle status (the SPA renders it).
        $statusByUuid = [];
        foreach ($body['data'] as $row) {
            $statusByUuid[(string) $row['tenant']['uuid']] = (string) $row['tenant']['status'];
        }
        self::assertSame('suspended', $statusByUuid[$suspended]);
        self::assertSame('provisioning', $statusByUuid[$provisioning]);

        // Consistency with the detail route: what the index shows, `getTenant()` also resolves...
        $suspendedDetail = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces/' . $suspended, $key));
        self::assertSame(200, $suspendedDetail->getStatusCode(), (string) $suspendedDetail->getContent());
        // ...and what it hides, the detail route 404s -- no more list-then-404-on-click.
        self::assertSame(
            404,
            $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces/' . $deleted, $key))
                ->getStatusCode(),
        );
    }

    public function testBillingWritesAreRefusedOnNonActiveWorkspacesButReadsStillWork(): void
    {
        $this->enableTenancy();
        $planKey = $this->seedPlan('inactive-plan');
        $active = $this->seedTenant('write-active');
        // Seeded ACTIVE, subscribed, THEN suspended -- so the read assertions below prove the detail
        // route still projects real subscription/override state for a non-active workspace, not just
        // that it happens to answer 200 for an empty one.
        $suspended = $this->seedTenant('write-suspended');
        $this->startSubscription($suspended, $planKey);
        $this->connection()->table('tenants')->where('uuid', $suspended)->update(['status' => 'suspended']);
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);

        $writes = [
            ['PUT', "/workspaces/{$suspended}/plan", ['plan_key' => $planKey]],
            ['POST', "/workspaces/{$suspended}/cancel", []],
            ['PUT', "/workspaces/{$suspended}/overrides/widgets.limit", ['value' => 5]],
            ['DELETE', "/workspaces/{$suspended}/overrides/widgets.limit", null],
        ];

        foreach ($writes as [$method, $path, $body]) {
            $response = $this->handle($this->apiKeyRequest($method, self::BASE . $path, $key, $body));
            self::assertSame(409, $response->getStatusCode(), "{$method} {$path}: " . (string) $response->getContent());
            self::assertSame('workspace_not_active', $this->errorCode($response), "{$method} {$path}");
        }

        // Reads on the same suspended workspace are untouched -- detail still projects the real
        // subscription (a non-active workspace cannot be ENTERED, so this read goes through the
        // engine's trusted administrative lane instead; see readSubscription()/readOverrides()).
        $read = $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces/' . $suspended, $key));
        self::assertSame(200, $read->getStatusCode(), (string) $read->getContent());
        $detail = $this->data($read);
        self::assertSame('suspended', $detail['tenant']['status']);
        self::assertSame($planKey, $detail['subscription']['plan_key'] ?? null);
        self::assertIsArray($detail['overrides']);

        // ...and so is the index projection for it.
        $listed = $this->fullBody(
            $this->handle($this->apiKeyRequest('GET', self::BASE . '/workspaces?per_page=100', $key)),
        );
        $listedUuids = array_column(array_column($listed['data'], 'tenant'), 'uuid');
        self::assertContains($suspended, $listedUuids);

        // ...and an ACTIVE workspace is completely unaffected by the guard.
        $ok = $this->handle($this->apiKeyRequest(
            'PUT',
            self::BASE . "/workspaces/{$active}/plan",
            $key,
            ['plan_key' => $planKey],
        ));
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
    }

    // ==================================================================
    // Final-wave fix D: override write validation + exception mapping
    // ==================================================================

    /**
     * Every one of these used to reach the engine's writer unvalidated: an unparseable/over-length
     * value became a driver-level 500 on the column, and a non-scalar `value` reached every
     * downstream entitlement consumer as an array.
     */
    public function testOverrideUpsertValidatesValueExpiryReasonAndEntitlementLength(): void
    {
        $this->enableTenancy();
        $tenant = $this->seedTenant('override-validation');
        $key = $this->seedApiKeyUser(['tenancy.manage'], ['tenancy.manage']);
        $path = static fn (string $entitlement): string
            => self::BASE . "/workspaces/{$tenant}/overrides/" . rawurlencode($entitlement);

        $cases = [
            'missing value' => ['widgets.limit', []],
            'array value' => ['widgets.limit', ['value' => ['nested' => true]]],
            'float value' => ['widgets.limit', ['value' => 1.5]],
            'string value' => ['widgets.limit', ['value' => 'yes']],
            'unparseable expires_at' => ['widgets.limit', ['value' => 5, 'expires_at' => 'not-a-date']],
            'empty expires_at' => ['widgets.limit', ['value' => 5, 'expires_at' => '  ']],
            'non-string expires_at' => ['widgets.limit', ['value' => 5, 'expires_at' => 12345]],
            'non-string reason' => ['widgets.limit', ['value' => 5, 'reason' => ['why']]],
            'over-length reason' => ['widgets.limit', ['value' => 5, 'reason' => str_repeat('r', 256)]],
            'over-length entitlement' => [str_repeat('e', 129), ['value' => 5]],
        ];

        foreach ($cases as $label => [$entitlement, $body]) {
            $response = $this->handle($this->apiKeyRequest('PUT', $path($entitlement), $key, $body));
            self::assertSame(422, $response->getStatusCode(), "{$label}: " . (string) $response->getContent());
        }

        // The accepted shapes still round-trip: bool, int, null, a parseable expiry, a bounded reason.
        foreach ([true, 42, null] as $i => $value) {
            $accepted = $this->handle($this->apiKeyRequest('PUT', $path("ok.{$i}"), $key, [
                'value' => $value,
                'expires_at' => '2099-01-01 00:00:00',
                'reason' => str_repeat('r', 255),
            ]));
            self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getContent());
            self::assertSame($value, $this->data($accepted)['value']);
        }

        // `expires_at` may be omitted entirely or explicitly null -- neither is a validation error.
        self::assertSame(
            200,
            $this->handle($this->apiKeyRequest('PUT', $path('ok.null-expiry'), $key, [
                'value' => 1,
                'expires_at' => null,
                'reason' => null,
            ]))->getStatusCode(),
        );
    }

    /**
     * The `catch (\InvalidArgumentException)` → 422 mapping every sibling action already carried.
     * Driven through the SAME recording-runner seam the cross-workspace override test uses, with a
     * runner that raises the upstream exception shape instead.
     */
    public function testOverrideWritesMapInvalidArgumentExceptionTo422(): void
    {
        $tenant = $this->seedTenant('override-mapping');
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', $tenant);

        $controller = $this->workspaceControllerWithRunner(new class implements TenantContextRunner {
            public function runAsTenant(string $tenantUuid, callable $fn): mixed
            {
                throw new \InvalidArgumentException('upstream rejected this override');
            }

            public function runAsSystem(callable $fn): mixed
            {
                return $fn();
            }

            public function forEachTenant(callable $fn): void
            {
                throw new \RuntimeException('not exercised');
            }
        });

        $upsert = $controller->upsertOverride(
            Request::create('/', 'PUT', [], [], [], [], (string) json_encode(['value' => 5])),
            $tenant,
            'widgets.limit',
        );
        self::assertSame(422, $upsert->getStatusCode(), (string) $upsert->getContent());
        self::assertStringContainsString('upstream rejected this override', (string) $upsert->getContent());

        $delete = $controller->deleteOverride(Request::create('/', 'DELETE'), $tenant, 'widgets.limit');
        self::assertSame(422, $delete->getStatusCode(), (string) $delete->getContent());
        self::assertStringContainsString('upstream rejected this override', (string) $delete->getContent());
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function enableTenancy(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');
    }

    /**
     * `$status`/`$softDeleted` (final-wave fix C): the directory-visibility and non-active-write
     * tests need the lifecycle states `ContractTenantAdministration` produces -- `provisioning`,
     * `suspended`, and the soft-deleted (`deleted_at` set) shape `deleteTenant()` leaves behind.
     */
    private function seedTenant(string $slugSuffix, string $status = 'active', bool $softDeleted = false): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'wba-' . $slugSuffix . '-' . substr($uuid, 0, 6),
            'name' => 'WBA ' . $slugSuffix,
            'status' => $status,
        ]);

        if ($softDeleted) {
            $this->connection()->table('tenants')->where('uuid', $uuid)->update([
                'deleted_at' => gmdate('Y-m-d H:i:s'),
                'deleted_from_status' => 'active',
                'purge_after' => gmdate('Y-m-d H:i:s', time() + 86400),
            ]);
        }

        return $uuid;
    }

    /** A mutable counter the readiness stub below increments on every full probe. */
    private function countingReadinessProbe(): object
    {
        return new class {
            public int $calls = 0;

            public function isReady(): bool
            {
                $this->calls++;

                return true;
            }
        };
    }

    /**
     * The real shared container with ONLY `SubscriptionSchemaReadiness::class` swapped for a probe
     * counter -- the same wrap-the-real-container idiom as
     * {@see self::contextWithStubbedReadiness()}, just counting instead of answering false.
     */
    private function workspaceControllerWithReadinessProbe(object $counter): WorkspaceBillingController
    {
        $real = $this->appContext();
        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($real->getContainer(), $counter) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $real,
                private readonly object $counter,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === \Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness::class) {
                    return $this->counter;
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === \Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness::class
                    || $this->real->has($id);
            }
        });

        return new WorkspaceBillingController(
            $context,
            new EngineGateway($context),
            $this->container()->get(TenantAdministration::class),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SystemFlags::class),
        );
    }

    private function seedPlan(string $keySuffix): string
    {
        $planKey = 'wba-' . $keySuffix . '-' . strtolower(substr(Utils::generateNanoID(), 0, 6));
        $this->container()->get(EngineGateway::class)->plans()->create([
            'plan_key' => $planKey,
            'display_name' => ucfirst($keySuffix) . ' Plan',
            'entitlements' => ['widgets.limit' => 3],
            'status' => 'active',
        ]);

        return $planKey;
    }

    /** @param array<string,mixed> $opts @return array<string,mixed> */
    private function startSubscription(string $tenantUuid, string $planKey, array $opts = []): array
    {
        return $this->container()->get(EngineGateway::class)->subscriptions()->start($tenantUuid, $planKey, $opts);
    }

    private function workspaceControllerWithRunner(TenantContextRunner $runner): WorkspaceBillingController
    {
        $real = $this->appContext();
        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($real->getContainer(), $runner) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $real,
                private readonly TenantContextRunner $runner,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === TenantContextRunner::class) {
                    return $this->runner;
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === TenantContextRunner::class || $this->real->has($id);
            }
        });

        return new WorkspaceBillingController(
            $context,
            new EngineGateway($context),
            $this->container()->get(TenantAdministration::class),
            $this->container()->get(SingleStoreTenant::class),
            $this->container()->get(SystemFlags::class),
        );
    }

    /**
     * A hand-built {@see EngineGateway} wrapping the REAL container except for
     * `SubscriptionService::class`, which resolves as absent -- the DISABLED trigger. Mirrors
     * PlansAdminApiTest::disabledGateway()/EngineGatewayTest's identical wrap-the-real-container
     * idiom.
     */
    private function disabledGateway(): EngineGateway
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
                if ($id === SubscriptionService::class) {
                    return false;
                }

                return $this->real->has($id);
            }
        });

        return new EngineGateway($context);
    }

    /** Mirrors EngineGatewayTest::contextWithStubbedReadiness(). */
    private function contextWithStubbedReadiness(bool $ready): ApplicationContext
    {
        $real = $this->appContext();
        $stub = new class ($ready) {
            public function __construct(private readonly bool $ready)
            {
            }

            public function isReady(): bool
            {
                return $this->ready;
            }
        };

        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($real->getContainer(), $stub) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $real,
                private readonly object $readinessStub,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === \Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness::class) {
                    return $this->readinessStub;
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === \Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness::class
                    || $this->real->has($id);
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

    /** @return array<string,mixed> the full decoded envelope (data + flattened pagination meta) */
    private function fullBody(\Glueful\Http\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, (string) $response->getContent());

        return $decoded;
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
            'username' => 'wba_' . substr($userUuid, 0, 8),
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
            'name' => 'workspace-billing-api-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'wbaapi_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'workspace billing api test role',
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
