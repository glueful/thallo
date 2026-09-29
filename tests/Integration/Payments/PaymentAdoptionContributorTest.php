<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionContributor;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionRefusedException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;

/**
 * Payments take part in enable-time adoption: the app registers a contributor next to commerce's,
 * so the flip moves payments' unassigned rows into the default workspace along with the orders
 * they pay for. Before, nothing did, and every payment taken before enablement vanished from the
 * workspace's reads.
 */
final class PaymentAdoptionContributorTest extends AppTestCase
{
    protected function tearDown(): void
    {
        PaymentTenantConstraints::drop($this->connection());
        $this->connection()->getPDO()->exec('DELETE FROM payments');
        parent::tearDown();
    }

    public function testTheAppRegistersPaymentsForAdoption(): void
    {
        $ids = array_map(
            static fn ($contributor): string => $contributor->id(),
            $this->container()->get(AdoptionContributorRegistry::class)->all(),
        );

        self::assertContains(PaymentAdoptionContributor::ID, $ids);
        self::assertContains('thallo.commerce', $ids);
    }

    public function testItOwnsPaymentsTablesAndMovesTheirUnassignedRows(): void
    {
        $contributor = $this->container()->get(PaymentAdoptionContributor::class);
        self::assertSame(DiagnosticsReport::tenantTables(), $contributor->tables());

        $this->connection()->table('payments')->insert([
            'uuid' => 'paycontrib01', 'tenant_uuid' => '', 'gateway' => 'paystack',
            'reference' => 'refcontrib01', 'amount' => 100,
        ]);
        $contributor->adopt($this->appContext(), 'tenDefault01');

        self::assertSame(
            'tenDefault01',
            $this->connection()->table('payments')->where('uuid', '=', 'paycontrib01')->first()['tenant_uuid'],
        );
    }

    /**
     * A refusal at the flip leaves the site mid-enablement: not widened, so the repair command does
     * not run. The message names the rows and says how to recover from there.
     */
    public function testARefusalAtTheFlipSaysHowToRecoverFromThere(): void
    {
        foreach (['', 'tenDefault01'] as $i => $tenant) {
            $this->connection()->table('billing_plans')->insert([
                'uuid' => 'plancontrib' . $i, 'tenant_uuid' => $tenant, 'name' => 'Monthly',
                'gateway' => 'paystack', 'amount' => 500,
            ]);
        }

        try {
            $this->container()->get(PaymentAdoptionContributor::class)->adopt($this->appContext(), 'tenDefault01');
            self::fail('a collision must refuse the flip');
        } catch (PaymentAdoptionRefusedException $refused) {
            $message = $refused->getMessage();
            self::assertStringContainsString('billing_plans #', $message);
            self::assertStringContainsString('gateway=paystack, name=Monthly', $message);
            self::assertStringContainsString('Nothing was changed', $message);
            self::assertStringContainsString('php glueful thallo:tenancy:enable --retry', $message);
            self::assertStringNotContainsString('payments:repair', $message);
        } finally {
            $this->connection()->getPDO()->exec('DELETE FROM billing_plans');
        }
    }
}
