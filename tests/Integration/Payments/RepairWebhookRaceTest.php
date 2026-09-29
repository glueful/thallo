<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Jobs\ProcessWebhookJob;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Payments\Console\RepairPaymentTenancyCommand;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\PaymentTenantConstraints;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The repair against a subscription webhook, on a site that turned workspaces on before payments
 * were adopted. Payvia's webhook reads a subscription's owner from the row, then updates it
 * qualified by that owner; a repair moving the row in between would leave the update matching
 * nothing, and the event acknowledged as processed with the change lost.
 *
 * Until the repair guards payments, every statement on a payments table holds the adoption gate for
 * its unit of work, and the repair closes it exclusively. So an ownership read before the repair
 * keeps the repair out until its write has landed, and a webhook arriving during the repair is
 * refused — recorded failed, not processed — and its retry, through Payvia's own job, applies the
 * change in the default workspace, once.
 */
final class RepairWebhookRaceTest extends AppTestCase
{
    private const DEFAULT = 'tenDefault01';

    private ?AdoptionGate $repairGate = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clear();
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', self::DEFAULT);
        $this->connection()->getPDO()->exec(
            "INSERT INTO billing_plans (uuid, tenant_uuid, name, gateway, amount) "
            . "VALUES ('planrace0001', '', 'Monthly', 'stripe', 900)"
        );
    }

    protected function tearDown(): void
    {
        $this->repairGate?->releaseExclusive();
        $this->gate()->release();
        PaymentTenantConstraints::drop($this->connection());
        $this->clear();
        parent::tearDown();
    }

    private function clear(): void
    {
        foreach (['gateway_subscriptions', 'billing_plans', 'provider_events'] as $table) {
            $this->connection()->getPDO()->exec("DELETE FROM {$table}");
        }
    }

    private function gate(): AdoptionGate
    {
        return $this->container()->get(AdoptionGate::class);
    }

    private function subscription(string $id): string
    {
        $uuid = 'subrace' . substr(md5($id), 0, 5);
        $this->connection()->getPDO()->exec(
            'INSERT INTO gateway_subscriptions (uuid, tenant_uuid, gateway, gateway_subscription_id, '
            . "billing_plan_uuid, status) VALUES ('{$uuid}', '', 'stripe', '{$id}', 'planrace0001', 'active')"
        );

        return $uuid;
    }

    /** Raw PDO: the test inspects rows even while the repair holds the gate. @return array<string, mixed> */
    private function row(string $table, string $uuid): array
    {
        $statement = $this->connection()->getPDO()->prepare("SELECT * FROM {$table} WHERE uuid = ?");
        $statement->execute([$uuid]);

        return (array) $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array{int, string} */
    private function repair(): array
    {
        $tester = new CommandTester(new RepairPaymentTenancyCommand(
            $this->container(),
            $this->appContext(),
            gateTimeoutMs: 200,
        ));
        $status = $tester->execute(['--apply' => true], ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    /** Ownership read, then the repair, then the webhook's write: the write lands, then moves. */
    public function testAWebhookThatReadTheOwnerKeepsTheRepairOutUntilItsWriteLands(): void
    {
        $uuid = $this->subscription('sub_race_read');
        $subscriptions = $this->container()->get(ProviderCorrelationRepository::class);

        $owner = (string) $subscriptions->findGatewaySubscriptionByGatewayId('stripe', 'sub_race_read')['tenant_uuid'];
        self::assertSame('', $owner);
        self::assertTrue($this->gate()->isHeld(), 'the ownership read holds the gate for its unit of work');

        [$status, $display] = $this->repair();
        self::assertSame(1, $status, $display);
        self::assertStringContainsString('still running', $display);
        self::assertSame('', $this->row('gateway_subscriptions', $uuid)['tenant_uuid'], 'the repair moved nothing');

        self::assertTrue(
            $subscriptions->updateGatewaySubscriptionOwned($owner, $uuid, ['status' => 'canceled']),
            'the webhook write finds the row where it read it',
        );
        $this->gate()->release(); // the webhook's unit of work ends

        [$status, $display] = $this->repair();
        self::assertSame(0, $status, $display);
        self::assertSame(
            ['tenant_uuid' => self::DEFAULT, 'status' => 'canceled'],
            array_intersect_key($this->row('gateway_subscriptions', $uuid), ['tenant_uuid' => 1, 'status' => 1]),
            'the update survived the move',
        );
    }

    /** A webhook during the repair is refused and recorded failed; its retry applies it once. */
    public function testAWebhookDuringTheRepairIsRetriedIntoTheDefaultWorkspace(): void
    {
        $uuid = $this->subscription('sub_race_during');
        $event = (string) $this->container()->get(ProviderEventRepositoryInterface::class)->insertReceived([
            'gateway' => 'stripe', 'source' => 'webhook', 'provider_event_id' => 'evt_race_during',
            'delivery_key' => 'stripe:evt_race_during', 'logical_event_key' => 'stripe:evt_race_during',
            'type' => 'subscription.canceled', 'signature_valid' => true,
            'normalized_payload' => ['gateway_subscription_id' => 'sub_race_during', 'status' => 'canceled'],
            'raw_payload' => [],
        ]);

        $this->repairGate = new AdoptionGate($this->connection());
        $this->repairGate->acquireExclusive(200); // the repair is moving rows
        $job = new ProcessWebhookJob(['provider_event_uuid' => $event], $this->appContext());
        try {
            $job->handle();
            self::fail('a webhook during the repair must be refused');
        } catch (RetrofitInProgressException) {
        }
        self::assertSame('failed', $this->row('provider_events', $event)['status'], 'recorded failed, not processed');
        self::assertSame('active', $this->row('gateway_subscriptions', $uuid)['status']);

        $this->repairGate->releaseExclusive();
        [$status, $display] = $this->repair();
        self::assertSame(0, $status, $display);
        self::assertSame(
            '1',
            $this->container()->get(SystemFlags::class)->get(PaymentTenancyAdoption::GUARDED_FLAG),
        );

        (new ProcessWebhookJob(['provider_event_uuid' => $event], $this->appContext()))->handle(); // the retry

        $stored = $this->row('provider_events', $event);
        self::assertSame(['processed', 2], [$stored['status'], (int) $stored['attempts']]);
        self::assertSame(
            ['tenant_uuid' => self::DEFAULT, 'status' => 'canceled'],
            array_intersect_key($this->row('gateway_subscriptions', $uuid), ['tenant_uuid' => 1, 'status' => 1]),
        );
        $count = $this->connection()->getPDO()
            ->query("SELECT count(*) FROM gateway_subscriptions WHERE gateway_subscription_id = 'sub_race_during'");
        self::assertSame(1, (int) $count->fetchColumn(), 'no duplicate subscription');
    }
}
