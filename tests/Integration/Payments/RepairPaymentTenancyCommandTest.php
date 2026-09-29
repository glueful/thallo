<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Payments\Console\RepairPaymentTenancyCommand;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;
use Thallo\Tenancy\System\SystemFlags;

/**
 * `thallo:tenancy:payments:repair` — for sites that turned workspaces on before payments were
 * adopted: a dry run by default that counts each table and names every collision, row another
 * workspace owns and duplicate intent; `--apply` moves the unassigned rows or refuses without
 * writing; running it again finds nothing to do. It refuses outright where there is no widened
 * schema or no recorded default workspace to move into.
 */
final class RepairPaymentTenancyCommandTest extends AppTestCase
{
    private const DEFAULT = 'tenDefault01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clear();
    }

    protected function tearDown(): void
    {
        PaymentTenantConstraints::drop($this->connection());
        $this->clear();
        parent::tearDown();
    }

    private function clear(): void
    {
        foreach (['payments', 'payment_intents', 'billing_plans', 'commerce_orders'] as $table) {
            $this->connection()->getPDO()->exec("DELETE FROM {$table}");
        }
    }

    private function widen(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::DEFAULT);
    }

    /** @return array{int, string} */
    private function repair(array $options = []): array
    {
        // The repair runs as its own process: this test's seeding was a unit of work that has ended.
        $this->container()->get(\Thallo\Tenancy\Adoption\AdoptionGate::class)->release();
        $tester = new CommandTester(new RepairPaymentTenancyCommand($this->container(), $this->appContext()));
        $status = $tester->execute($options, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    private function payment(string $uuid, string $tenant): void
    {
        $this->connection()->table('payments')->insert([
            'uuid' => $uuid, 'tenant_uuid' => $tenant, 'gateway' => 'paystack', 'reference' => 'ref' . $uuid,
            'amount' => 1000, 'status' => 'success',
        ]);
    }

    private function tenantOf(string $uuid): string
    {
        return (string) $this->connection()->table('payments')->where('uuid', '=', $uuid)->first()['tenant_uuid'];
    }

    public function testItRefusesWithoutWorkspaces(): void
    {
        [$status, $display] = $this->repair();

        self::assertSame(1, $status);
        self::assertStringContainsString('Workspaces are not turned on', $display);
    }

    public function testItRefusesWithoutADefaultWorkspace(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.schema_state', 'widened');

        [$status, $display] = $this->repair();

        self::assertSame(1, $status);
        self::assertStringContainsString('no default workspace is recorded', $display);
    }

    public function testTheDryRunReportsAndWritesNothing(): void
    {
        $this->widen();
        $this->payment('payrepair001', '');
        $this->payment('payrepair002', 'tenOther0001');

        [$status, $display] = $this->repair();

        self::assertSame(0, $status, $display);
        self::assertMatchesRegularExpression('~payments\s+1\s+0\s+1~', $display);
        self::assertStringContainsString('Dry run: nothing was changed', $display);
        self::assertSame('', $this->tenantOf('payrepair001'));
    }

    public function testApplyMovesThenFindsNothingToDo(): void
    {
        $this->widen();
        $this->payment('payrepair001', '');
        $this->payment('payrepair002', 'tenOther0001');

        [$status, $display] = $this->repair(['--apply' => true]);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString('Moved 1 unassigned row(s) into workspace ' . self::DEFAULT, $display);
        self::assertSame(self::DEFAULT, $this->tenantOf('payrepair001'));
        self::assertSame('tenOther0001', $this->tenantOf('payrepair002'));

        [$again, $display] = $this->repair(['--apply' => true]);
        self::assertSame(0, $again, $display);
        self::assertStringContainsString('Nothing to repair', $display);
    }

    public function testACollisionRefusesTheApplyWithoutWriting(): void
    {
        $this->widen();
        foreach (['', self::DEFAULT] as $i => $tenant) {
            $this->connection()->table('billing_plans')->insert([
                'uuid' => 'planrepair0' . $i, 'tenant_uuid' => $tenant, 'name' => 'Monthly',
                'gateway' => 'paystack', 'amount' => 500,
            ]);
        }
        $this->payment('payrepair001', '');

        [$status, $display] = $this->repair(['--apply' => true]);

        self::assertSame(1, $status);
        self::assertStringContainsString('billing_plans', $display);
        self::assertStringContainsString('gateway=paystack, name=Monthly', $display);
        self::assertStringContainsString('Refused: nothing was changed', $display);
        self::assertSame('', $this->tenantOf('payrepair001'));
    }

    public function testDuplicateIntentsAreListedForReconciliation(): void
    {
        $this->widen();
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => 'orderrepair1', 'tenant_uuid' => self::DEFAULT,
        ]);
        foreach (['' => 'intrepair001', self::DEFAULT => 'intrepair002'] as $tenant => $uuid) {
            $this->connection()->table('payment_intents')->insert([
                'uuid' => $uuid, 'tenant_uuid' => (string) $tenant, 'payable_type' => 'commerce_order',
                'payable_id' => 'orderrepair1', 'idempotency_key' => 'retired:' . $uuid, 'gateway' => 'paystack',
                'reference' => 'ref' . $uuid, 'status' => 'closed', 'amount' => 1000, 'currency' => 'GHS',
            ]);
        }

        [$status, $display] = $this->repair(['--json' => true]);

        self::assertSame(0, $status, $display);
        $report = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('orderrepair1', $report['duplicates'][0]['payable_id']);
        self::assertSame(['intrepair001', 'intrepair002'], array_column($report['duplicates'][0]['intents'], 'uuid'));
        self::assertFalse($report['applied']);
    }
}
