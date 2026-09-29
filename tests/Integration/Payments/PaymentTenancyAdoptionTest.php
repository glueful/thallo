<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Thallo\Core\Payments\Tenancy\PaymentAdoptionRefusedException;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;

/**
 * Moving unassigned payment rows into the default workspace — at the adoption flip and in the
 * repair of sites enabled before it: only tenant '' rows move, ids and relationships unchanged,
 * rows other workspaces own untouched; a key the default workspace already holds or a row another
 * workspace owns refuses the whole move; the move is repeatable; duplicate intents are reported,
 * never resolved; and afterwards the database refuses a fresh unassigned row.
 */
final class PaymentTenancyAdoptionTest extends AppTestCase
{
    private const DEFAULT = 'tenDefault01';
    private const OTHER = 'tenOther0001';

    private const TABLES = ['payments', 'payment_intents', 'billing_plans', 'invoices', 'commerce_orders'];

    private static int $seq = 0;

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
        foreach (self::TABLES as $table) {
            $this->connection()->getPDO()->exec("DELETE FROM {$table}");
        }
    }

    private function adoption(): PaymentTenancyAdoption
    {
        return $this->container()->get(PaymentTenancyAdoption::class);
    }

    private static function uuid(string $prefix): string
    {
        return $prefix . str_pad((string) ++self::$seq, 12 - strlen($prefix), '0', STR_PAD_LEFT);
    }

    /** @return array{id: int, uuid: string} */
    private function insert(string $table, array $row): array
    {
        $row['uuid'] ??= self::uuid(substr($table, 0, 3));
        $this->connection()->table($table)->insert($row);
        $id = (int) $this->connection()->table($table)->where('uuid', '=', $row['uuid'])->first()['id'];

        return ['id' => $id, 'uuid' => $row['uuid']];
    }

    private function payment(string $tenant, array $row = []): array
    {
        return $this->insert('payments', $row + [
            'tenant_uuid' => $tenant, 'gateway' => 'paystack', 'reference' => self::uuid('ref'),
            'amount' => 1000, 'status' => 'success',
        ]);
    }

    private function intent(string $tenant, string $orderUuid, string $status, array $row = []): array
    {
        return $this->insert('payment_intents', $row + [
            'tenant_uuid' => $tenant, 'payable_type' => 'commerce_order', 'payable_id' => $orderUuid,
            'idempotency_key' => 'retired:' . self::uuid('key'), 'gateway' => 'paystack',
            'reference' => self::uuid('ref'), 'status' => $status, 'amount' => 1000, 'currency' => 'GHS',
        ]);
    }

    private function plan(string $tenant, string $name): array
    {
        return $this->insert('billing_plans', [
            'tenant_uuid' => $tenant, 'name' => $name, 'gateway' => 'paystack', 'amount' => 500,
        ]);
    }

    private function order(string $tenant): string
    {
        return $this->insert('commerce_orders', ['tenant_uuid' => $tenant])['uuid'];
    }

    private function tenantOf(string $table, int $id): string
    {
        return (string) $this->connection()->table($table)->where('id', '=', $id)->first()['tenant_uuid'];
    }

    public function testTheDryRunCountsEachTableWithoutWriting(): void
    {
        $this->payment('');
        $this->payment('');
        $this->payment(self::DEFAULT);
        $this->payment(self::OTHER);
        $this->plan('', 'Monthly');

        $report = $this->adoption()->diagnose(self::DEFAULT);

        self::assertSame(['unassigned' => 2, 'default' => 1, 'other' => 1], $report->counts['payments']);
        self::assertSame(['unassigned' => 1, 'default' => 0, 'other' => 0], $report->counts['billing_plans']);
        self::assertSame(3, $report->unassignedTotal());
        self::assertFalse($report->refused());
        self::assertSame(2, $this->connection()->table('payments')->where('tenant_uuid', '=', '')->count());
    }

    public function testTheMoveTakesOnlyUnassignedRowsAndKeepsTheirIdentity(): void
    {
        $order = $this->order(self::DEFAULT);
        $plan = $this->plan('', 'Monthly');
        $invoice = $this->insert('invoices', [
            'tenant_uuid' => '', 'number' => 'INV-1', 'amount' => 500, 'billing_plan_uuid' => $plan['uuid'],
        ]);
        $unassigned = $this->intent('', $order, 'closed');
        $defaults = $this->payment(self::DEFAULT);
        $others = $this->payment(self::OTHER);

        $report = $this->adoption()->apply(self::DEFAULT);

        self::assertSame(1, $report->moved['payment_intents']);
        self::assertSame(self::DEFAULT, $this->tenantOf('payment_intents', $unassigned['id']));
        self::assertSame(self::DEFAULT, $this->tenantOf('billing_plans', $plan['id']));
        $moved = $this->connection()->table('invoices')->where('id', '=', $invoice['id'])->first();
        self::assertSame([self::DEFAULT, $invoice['uuid'], $plan['uuid']], [
            $moved['tenant_uuid'], $moved['uuid'], $moved['billing_plan_uuid'],
        ], 'the id, the uuid and the relationship are unchanged');
        self::assertSame(self::DEFAULT, $this->tenantOf('payments', $defaults['id']));
        self::assertSame(self::OTHER, $this->tenantOf('payments', $others['id']), 'another workspace keeps its rows');
    }

    public function testTheMoveIsRepeatable(): void
    {
        $this->payment('');
        $this->adoption()->apply(self::DEFAULT);

        $again = $this->adoption()->apply(self::DEFAULT);

        self::assertSame(0, array_sum($again->moved));
        self::assertSame(0, $again->unassignedTotal());
        self::assertSame(1, $this->connection()->table('payments')->where('tenant_uuid', '=', self::DEFAULT)->count());
    }

    public function testAfterTheMoveTheDatabaseRefusesAnUnassignedRow(): void
    {
        $this->adoption()->apply(self::DEFAULT);
        self::assertContains('payments', $this->adoption()->diagnose(self::DEFAULT)->constrained);

        $this->expectException(\Throwable::class);
        $this->payment('');
    }

    public function testAKeyTheDefaultWorkspaceAlreadyHoldsRefusesTheWholeMove(): void
    {
        $this->plan(self::DEFAULT, 'Monthly');
        $clash = $this->plan('', 'Monthly');
        $innocent = $this->payment('');

        $report = $this->adoption()->diagnose(self::DEFAULT);
        self::assertTrue($report->refused());
        self::assertSame('billing_plans', $report->collisions[0]['table']);
        self::assertSame($clash['id'], $report->collisions[0]['unassigned_id']);
        self::assertSame(['gateway' => 'paystack', 'name' => 'Monthly'], $report->collisions[0]['key']);

        try {
            $this->adoption()->apply(self::DEFAULT);
            self::fail('a collision must refuse the move');
        } catch (PaymentAdoptionRefusedException $refused) {
            self::assertSame($clash['id'], $refused->report->collisions[0]['unassigned_id']);
        }
        self::assertSame('', $this->tenantOf('payments', $innocent['id']), 'nothing moved');
        self::assertSame([], $this->adoption()->diagnose(self::DEFAULT)->constrained, 'nothing constrained');
    }

    public function testARowAnotherWorkspaceOwnsRefusesTheMove(): void
    {
        $theirs = $this->order(self::OTHER);
        $stray = $this->intent('', $theirs, 'open');
        $otherPlan = $this->plan(self::OTHER, 'Theirs');
        $invoice = $this->insert('invoices', [
            'tenant_uuid' => '', 'number' => 'INV-9', 'amount' => 500, 'billing_plan_uuid' => $otherPlan['uuid'],
        ]);

        $report = $this->adoption()->diagnose(self::DEFAULT);

        self::assertTrue($report->refused());
        $ambiguous = array_map(
            static fn (array $row): array => [$row['table'], $row['id'], $row['workspace']],
            $report->ambiguous,
        );
        self::assertContains(['payment_intents', $stray['id'], self::OTHER], $ambiguous);
        self::assertContains(['invoices', $invoice['id'], self::OTHER], $ambiguous);

        $this->expectException(PaymentAdoptionRefusedException::class);
        $this->adoption()->apply(self::DEFAULT);
    }

    /** Duplicates are reported for reconciliation before and after the move; the move keeps both. */
    public function testDuplicateIntentsForOneOrderAreReportedNotResolved(): void
    {
        $order = $this->order(self::DEFAULT);
        $first = $this->intent('', $order, 'closed');
        $second = $this->intent(self::DEFAULT, $order, 'closed');
        $this->payment('', ['payable_type' => 'commerce_order', 'payable_id' => $order]);
        $this->payment(self::DEFAULT, ['payable_type' => 'commerce_order', 'payable_id' => $order]);
        $single = $this->order(self::DEFAULT);
        $this->intent('', $single, 'failed');
        $this->intent('', $single, 'closed');

        $before = $this->adoption()->diagnose(self::DEFAULT);
        self::assertFalse($before->refused(), 'duplicates do not block the move');
        self::assertCount(1, $before->duplicates);
        self::assertSame($order, $before->duplicates[0]['payable_id']);
        self::assertSame([$first['uuid'], $second['uuid']], array_column($before->duplicates[0]['intents'], 'uuid'));
        self::assertSame(2, $before->duplicates[0]['settled_payments']);

        $after = $this->adoption()->apply(self::DEFAULT);
        self::assertCount(1, $after->duplicates);
        self::assertSame(2, $this->connection()->table('payment_intents')->where('payable_id', '=', $order)->count());
        self::assertCount(1, $this->adoption()->diagnose(self::DEFAULT)->duplicates, 'still reported after the move');
    }
}
