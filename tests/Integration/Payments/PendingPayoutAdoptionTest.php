<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayoutTransferRepository;
use Thallo\Core\Payments\Tenancy\PaymentTables;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Core\Settings\PlatformPaymentSettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Marketplace payouts follow the switch too. Payvia's transfer ledger is workspace-scoped by the same
 * resolver as payments but missing from its own tenant-table list, so it is in Thallo's payments
 * inventory: a payout started as the single store is still found in the default workspace
 * afterwards, and retrying it recovers the recorded attempt instead of paying out again.
 */
final class PendingPayoutAdoptionTest extends AppTestCase
{
    private const DEFAULT = 'tenDefault01';

    /** Disabled for this test: any attempt to reach it throws, so a clean recovery proves none. */
    private const GATEWAY = 'paystack';

    private const DISABLED = 'payvia.gateways.paystack.enabled';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM payvia_transfers');
        $this->container()->get(PlatformPaymentSettingsStore::class)->putMany([self::DISABLED => '0']);
    }

    protected function tearDown(): void
    {
        PaymentTenantConstraints::drop($this->connection());
        $this->connection()->getPDO()->exec('DELETE FROM payvia_transfers');
        $this->connection()->table('thallo_system_flags')->where('key', '=', self::DISABLED)->forceDelete();
        $this->container()->get(SystemFlags::class)->clearCache();
        parent::tearDown();
    }

    public function testTheTransferLedgerIsInThePaymentsInventory(): void
    {
        self::assertContains('payvia_transfers', PaymentTables::workspaceOwned());
        self::assertNotContains(
            'payvia_transfers',
            PaymentTables::backstopRegistered(),
            'payvia does not register it with the tenancy backstop, so the finalization probe must not require it',
        );
    }

    public function testAPendingPayoutIsRecoveredNotRepeatedAfterTheSwitch(): void
    {
        try {
            $this->container()->get(GatewayManager::class)->payoutGateway(self::GATEWAY);
            self::fail('sanity: the gateway must be unreachable in this test');
        } catch (\RuntimeException $disabled) {
            self::assertStringContainsString('disabled', $disabled->getMessage());
        }

        $transfers = $this->container()->get(PayoutTransferRepository::class);
        self::assertTrue($transfers->insertPending($this->appContext(), [
            'uuid' => 'trfpending01', 'gateway' => self::GATEWAY, 'idempotency_key' => 'payout:seller1:1',
            'provider_reference' => 'safe-ref-1', 'destination_ref' => 'ACCT_1', 'amount' => 5000,
            'currency' => 'GHS', 'request_payload' => ['amount' => 5000],
        ]));
        $transfers->setResult($this->appContext(), 'trfpending01', [
            'provider_ref' => 'TRF_PROVIDER_1', 'status' => 'pending', 'message' => null, 'raw_payload' => [],
        ]);

        $report = $this->container()->get(PaymentTenancyAdoption::class)->apply(self::DEFAULT);
        self::assertSame(1, $report->moved['payvia_transfers']);
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::DEFAULT);

        $found = $transfers->findByIdempotencyKey($this->appContext(), self::GATEWAY, 'payout:seller1:1');
        self::assertSame('trfpending01', $found['uuid'] ?? null, 'the default workspace finds the payout');

        $result = $this->container()->get(PayoutCollector::class)->transfer(
            $this->appContext(),
            new PayoutDestination(self::GATEWAY, 'ACCT_1'),
            new PayoutRequest(5000, 'GHS', 'payout:seller1:1'),
        );
        self::assertSame('pending', $result->status);
        self::assertSame('TRF_PROVIDER_1', $result->providerRef);
        self::assertSame(1, $this->connection()->table('payvia_transfers')->count(), 'no second attempt');
    }
}
