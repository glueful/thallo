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
