<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionContributor;
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
}
