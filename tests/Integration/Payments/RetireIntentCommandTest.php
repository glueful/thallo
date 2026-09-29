<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Payments\Console\RepairPaymentTenancyCommand;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\System\SystemFlags;

/**
 * `thallo:tenancy:payments:repair --retire-intent=<uuid>`: how an operator clears the duplicate
 * intent that blocks the repair. A dry run unless `--apply`; it shows the intent's workspace, order,
 * status, provider and reference; it reaches only unassigned intents and the default workspace's;
 * it supersedes through Payvia's own conditional retirement, so an intent that settled in the
 * meantime is never superseded; running it again changes nothing; and it says, every time, that
 * superseding does not cancel or refund anything at the provider.
 */
final class RetireIntentCommandTest extends AppTestCase
{
    private const DEFAULT = 'tenDefault01';
    private const ORDER = 'orderretire1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clear();
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::DEFAULT);
    }

    protected function tearDown(): void
    {
        PaymentTenantConstraints::drop($this->connection());
        $this->clear();
        parent::tearDown();
    }

    private function clear(): void
    {
        foreach (['payment_intents', 'payments'] as $table) {
            $this->connection()->getPDO()->exec("DELETE FROM {$table}");
        }
    }

    private function intent(string $uuid, string $tenant, string $status): void
    {
        $key = in_array($status, ['initializing', 'open'], true)
            ? 'commerce_order:' . self::ORDER
            : 'commerce_order:' . self::ORDER . ':' . $uuid;
        $this->connection()->getPDO()->exec(
            'INSERT INTO payment_intents (uuid, tenant_uuid, payable_type, payable_id, idempotency_key, gateway, '
            . "reference, status, amount, currency) VALUES ('{$uuid}', '{$tenant}', 'commerce_order', '"
            . self::ORDER . "', '{$key}', 'paystack', 'ref{$uuid}', '{$status}', 2500, 'GHS')"
        );
    }

    /** @return array<string, mixed> */
    private function row(string $uuid): array
    {
        $statement = $this->connection()->getPDO()->prepare('SELECT * FROM payment_intents WHERE uuid = ?');
        $statement->execute([$uuid]);

        return (array) $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array{int, string} */
    private function retire(string $uuid, bool $apply = false): array
    {
        $this->container()->get(AdoptionGate::class)->release(); // its own process
        $tester = new CommandTester(new RepairPaymentTenancyCommand($this->container(), $this->appContext()));
        $status = $tester->execute(
            ['--retire-intent' => $uuid] + ($apply ? ['--apply' => true] : []),
            ['interactive' => false],
        );

        return [$status, $tester->getDisplay()];
    }

    public function testTheDryRunShowsTheIntentAndChangesNothing(): void
    {
        $this->intent('intretire001', '', 'open');

        [$status, $display] = $this->retire('intretire001');

        self::assertSame(0, $status, $display);
        $expectations = [
            'intretire001', 'no workspace (unassigned)', 'commerce_order ' . self::ORDER, 'open', 'paystack',
            'refintretire001', 'Dry run: nothing was changed', 'does not cancel or refund anything at paystack',
        ];
        foreach ($expectations as $expected) {
            self::assertStringContainsString($expected, $display);
        }
        self::assertSame('open', $this->row('intretire001')['status']);
    }

    public function testApplySupersedesAndUnblocksTheRepairThenFindsNothingToDo(): void
    {
        $this->intent('intretire001', '', 'open');
        $this->intent('intretire002', self::DEFAULT, 'open');

        [$status, $display] = $this->retire('intretire002', true);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString('Superseded intretire002', $display);
        self::assertStringContainsString('does not cancel or refund anything at paystack', $display);
        $retired = $this->row('intretire002');
        self::assertSame(
            ['superseded', 'commerce_order:' . self::ORDER . ':intretire002', self::DEFAULT],
            [$retired['status'], $retired['idempotency_key'], $retired['tenant_uuid']],
            'Payvia\'s retirement: status and re-keyed port; the workspace unchanged',
        );

        $tester = new CommandTester(new RepairPaymentTenancyCommand($this->container(), $this->appContext()));
        self::assertSame(0, $tester->execute([], ['interactive' => false]), 'the repair is no longer refused');

        [$again, $display] = $this->retire('intretire002', true);
        self::assertSame(0, $again, $display);
        self::assertStringContainsString('already superseded', $display);
        self::assertSame($retired, $this->row('intretire002'));
    }

    public function testAnotherWorkspacesIntentIsOutOfReach(): void
    {
        $this->intent('intretire003', 'tenOther0001', 'open');

        [$status, $display] = $this->retire('intretire003', true);

        self::assertSame(1, $status);
        self::assertStringContainsString('belongs to another workspace', $display);
        self::assertSame('open', $this->row('intretire003')['status']);
    }

    /** Settled first: the conditional retirement refuses, and says it may have been paid. */
    public function testAnIntentThatSettledFirstIsNeverSuperseded(): void
    {
        $this->intent('intretire004', '', 'closed');

        [$status, $display] = $this->retire('intretire004', true);

        self::assertSame(1, $status);
        self::assertStringContainsString('is closed', $display);
        self::assertSame('closed', $this->row('intretire004')['status']);
    }

    /** A success webhook after the retirement still records the payment, so it can be refunded. */
    public function testALateSuccessfulWebhookStillRecordsThePayment(): void
    {
        $this->intent('intretire005', self::DEFAULT, 'open');
        $this->retire('intretire005', true);
        $payments = $this->container()->get(PaymentRepositoryInterface::class);
        $payments->createPayment($this->appContext(), [
            'gateway' => 'paystack', 'reference' => 'refintretire005', 'amount' => 2500, 'currency' => 'GHS',
            'status' => 'pending', 'payable_type' => 'commerce_order', 'payable_id' => self::ORDER,
        ]);

        $intents = $this->container()->get(PaymentIntentRepository::class);
        $late = $intents->findByReference($this->appContext(), 'paystack', 'refintretire005');
        $intents->close($this->appContext(), (string) $late['uuid'], 'refintretire005');
        self::assertTrue(
            $payments->updateByReference($this->appContext(), 'refintretire005', ['status' => 'success']),
        );

        self::assertSame('superseded', $this->row('intretire005')['status'], 'the retirement is not undone');
        self::assertSame(
            'success',
            $payments->findByReference($this->appContext(), 'refintretire005')['status'],
            'the paid attempt is on record for a refund',
        );
    }

    public function testAnUnknownIntentIsReported(): void
    {
        [$status, $display] = $this->retire('intmissing01', true);

        self::assertSame(1, $status);
        self::assertStringContainsString('No payment intent intmissing01', $display);
    }
}
