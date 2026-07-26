<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Exceptions\Client\ConflictException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\MarketplaceSettingsController;

/**
 * Store-settings spec §3.6 (Marketplace group): GET /v1/admin/commerce/marketplace +
 * activate/deactivate/commission. The behaviours that carry the weight: the boot-time master
 * flag is reported honestly and GATES every write (this install runs with it off — the honest
 * default), and with the flag on (test seam) the thin front drives commerce's real activation
 * and commission services against the real tables.
 */
final class CommerceMarketplaceEndpointTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $tenant = $this->tenant();
        $this->connection()->getPDO()->exec(
            "DELETE FROM commerce_marketplace_settings WHERE tenant_uuid = '{$tenant}'"
        );
        $this->connection()->table('settings')
            ->where(['key' => 'commerce.marketplace.enabled'])->delete();
        $this->container()->get(\App\Settings\SettingsStore::class)->clearCache();
    }

    public function testMasterOffIsReportedHonestlyAndGatesEveryWrite(): void
    {
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertFalse($data['master_enabled']);
        self::assertNull($data['settings']);
        self::assertSame([], $data['sellers']);

        foreach (
            [
                fn () => $this->controller()->activate($this->actorRequest('POST', [])),
                fn () => $this->controller()->deactivate(Request::create('/x', 'POST')),
                fn () => $this->controller()->updateCommission($this->actorRequest('PUT', [
                    'commission_kind' => 'percentage',
                    'commission_bps' => 500,
                ])),
            ] as $write
        ) {
            try {
                $write();
                self::fail('Expected ConflictException while the master flag is off');
            } catch (ConflictException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testActivateCommissionAndDeactivateDriveTheRealServices(): void
    {
        $controller = $this->controller(masterEnabled: true);

        // Activate (no products in this tenant — nothing needs a default seller).
        $data = $this->data($controller->activate($this->actorRequest('POST', [])));
        self::assertTrue($data['master_enabled']);
        self::assertSame('active', $data['settings']['status']);
        self::assertNull($data['settings']['default_seller_uuid']);

        // Workspace commission policy: a concrete percentage.
        $data = $this->data($controller->updateCommission($this->actorRequest('PUT', [
            'commission_kind' => 'percentage',
            'commission_bps' => 750,
        ])));
        self::assertSame('percentage', $data['settings']['commission']['kind']);
        self::assertSame(750, $data['settings']['commission']['bps']);
        self::assertNull($data['settings']['commission']['fixed']);

        // Deactivate is non-destructive: the row survives with its policy.
        $data = $this->data($controller->deactivate(Request::create('/x', 'POST')));
        self::assertNotSame('active', $data['settings']['status']);
        self::assertSame(750, $data['settings']['commission']['bps']);
    }

    public function testRawSettingsColumnsNeverLeakThroughTheProjection(): void
    {
        $controller = $this->controller(masterEnabled: true);
        $controller->activate($this->actorRequest('POST', []));

        $data = $this->data($controller->show(Request::create('/x')));
        self::assertIsArray($data['settings']);
        self::assertArrayNotHasKey('tenant_uuid', $data['settings']);
        self::assertArrayNotHasKey('activated_by', $data['settings']);
        self::assertArrayNotHasKey('uuid', $data['settings']);
        self::assertSame(['bps', 'days'], array_keys($data['settings']['reserve']));
    }

    public function testMasterToggleRoundTripsAndGatesFollowIt(): void
    {
        $controller = $this->controller();

        // Switch ON at runtime: stored row, honest flags, writes now pass the gate.
        $data = $this->data($controller->setMaster($this->jsonRequest('PUT', '/x', ['enabled' => true])));
        self::assertTrue($data['master_enabled']);
        self::assertTrue($data['master_overridden']);
        $row = $this->connection()->table('settings')
            ->where(['key' => 'commerce.marketplace.enabled'])->first();
        self::assertIsArray($row);
        self::assertSame('1', $row['value']);

        $data = $this->data($controller->activate($this->actorRequest('POST', [])));
        self::assertSame('active', $data['settings']['status']);

        // The seam chain (guarded for commerce 1.6.x): checkout's fast path sees the toggle.
        if (method_exists(\Glueful\Extensions\Commerce\Support\CommerceSettings::class, 'marketplaceEnabled')) {
            self::assertTrue(
                \Glueful\Extensions\Commerce\Support\CommerceSettings::marketplaceEnabled($this->appContext()),
            );
        }

        // Clear back to the env default (off): row deleted, writes gate again.
        $data = $this->data($controller->setMaster($this->jsonRequest('PUT', '/x', ['enabled' => null])));
        self::assertFalse($data['master_enabled']);
        self::assertFalse($data['master_overridden']);
        self::assertNull(
            $this->connection()->table('settings')
                ->where(['key' => 'commerce.marketplace.enabled'])->first()
        );
        $this->expectException(ConflictException::class);
        $controller->deactivate(Request::create('/x', 'POST'));
    }

    private function tenant(): string
    {
        return $this->container()->get(CommerceTenantResolution::class)
            ->tenantUuid($this->appContext());
    }

    private function controller(?bool $masterEnabled = null): MarketplaceSettingsController
    {
        if ($masterEnabled === null) {
            return $this->container()->get(MarketplaceSettingsController::class);
        }

        return new MarketplaceSettingsController(
            $this->appContext(),
            $this->container()->get(CommerceTenantResolution::class),
            masterEnabled: $masterEnabled,
        );
    }

    /**
     * Route middleware sets the post-auth `user` attribute in production; commission changes
     * REQUIRE it (audit trail), so these direct-controller requests carry a test actor.
     *
     * @param array<string,mixed> $body
     */
    private function actorRequest(string $method, array $body): Request
    {
        $request = $this->jsonRequest($method, '/x', $body);
        $request->attributes->set('user', ['uuid' => 'testactor001']);

        return $request;
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }
}
