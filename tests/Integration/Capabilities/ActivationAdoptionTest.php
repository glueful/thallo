<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Installer\DatabaseConfig;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Setup\CapabilityAdoption;
use Thallo\Core\Setup\CapabilityProvisioning;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ChildProcesses;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;
use Thallo\Core\Tests\Support\RestoresPermissionRows;

/**
 * Activation capabilities are off until their activation finalizes, except for the one-time upgrade
 * adoption (spec §7.3a, §7.7): eligibility is captured before provision's migrations on the target
 * database, the first committed capture is authoritative, and adoption only fills an absent state.
 */
final class ActivationAdoptionTest extends AppTestCase
{
    use ActivationRunners;
    use ChildProcesses;
    use ResetsCommerceActivation;
    use RestoresPermissionRows;

    private const IDS = ['thallo.commerce', 'thallo.subscriptions', 'thallo.payments'];

    /** @var array<string, string> flag key => value, saved before each test */
    private array $savedFlags = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $pdo = $this->connection()->getPDO();
        $saved = "SELECT key, value FROM thallo_system_flags WHERE key LIKE 'capability.thallo.%'";
        foreach ($pdo->query($saved) as $row) {
            $this->savedFlags[(string) $row['key']] = (string) $row['value'];
        }
        $this->clearUpgradeState();
    }

    protected function tearDown(): void
    {
        $this->clearUpgradeState();
        $pdo = $this->connection()->getPDO();
        $put = $pdo->prepare(
            'INSERT INTO thallo_system_flags (key, value) VALUES (?, ?)'
            . ' ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        foreach ($this->savedFlags as $key => $value) {
            $put->execute([$key, $value]);
        }
        $this->resetCommerceActivation();
        $this->restorePermissionRows();
        $this->removeActivationTempFiles();
        parent::tearDown();
    }

    /** No stored state, idle rows, no Payments row, no adoption record. */
    private function clearUpgradeState(): void
    {
        $pdo = $this->connection()->getPDO();
        $keys = array_map(static fn (string $id): string => "capability.{$id}.enabled", self::IDS);
        $in = "'" . implode("','", $keys) . "'";
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key IN ({$in})");
        $pdo->exec(
            "DELETE FROM capability_activation_events WHERE capability IN ('thallo.payments', 'thallo.subscriptions')"
        );
        $pdo->exec("DELETE FROM capability_activations WHERE capability = 'thallo.payments'");
        $pdo->exec(
            "UPDATE capability_activations SET generation = 0, status = 'idle', steps_done = '[]', owner_token = NULL,
               lease_expires_at = NULL, workspaces = '{}', result = '{}' WHERE capability = 'thallo.subscriptions'"
        );
        $this->resetCommerceActivation();
        $pdo->exec('DELETE FROM thallo_capability_adoption');
    }

    private function target(?string $schema = null): DatabaseConfig
    {
        return new DatabaseConfig(
            'pgsql',
            (string) env('DB_PGSQL_HOST', '127.0.0.1'),
            (int) env('DB_PGSQL_PORT', '5432'),
            (string) env('DB_PGSQL_DATABASE', 'app_test'),
            (string) env('DB_PGSQL_USERNAME', 'postgres'),
            (string) env('DB_PGSQL_PASSWORD', ''),
            $schema ?? (string) env('DB_PGSQL_SCHEMA', 'public'),
        );
    }

    private function adoption(?ContainerInterface $container = null): CapabilityAdoption
    {
        return ($container ?? $this->container())->get(CapabilityAdoption::class);
    }

    /** @return list<string> adopted ids */
    private function upgrade(?ContainerInterface $container = null, ?DatabaseConfig $target = null): array
    {
        $adoption = $this->adoption($container);
        $adoption->capture($target ?? $this->target());
        return $adoption->run();
    }

    private function stored(string $id): ?bool
    {
        return $this->container()->get(CapabilityStateStore::class)->fresh($id) === null
            ? null
            : $this->storedValue($id);
    }

    private function storedValue(string $id): ?bool
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT value FROM thallo_system_flags WHERE key = ?');
        $stmt->execute(["capability.{$id}.enabled"]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : in_array($value, ['true', '1'], true);
    }

    /** @return list<array<string, mixed>> the removed receipt, to put back */
    private function makePayviaPending(): array
    {
        $pdo = $this->connection()->getPDO();
        $row = $pdo->query("SELECT * FROM migrations WHERE source = 'glueful/payvia' ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'precondition: Payvia is migrated');
        $pdo->prepare('DELETE FROM migrations WHERE id = ?')->execute([$row['id']]);
        return [$row];
    }

    /** @param list<array<string, mixed>> $rows */
    private function restoreReceipts(array $rows): void
    {
        foreach ($rows as $row) {
            $columns = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            $this->connection()->getPDO()->prepare("INSERT INTO migrations ({$columns}) VALUES ({$marks})")
                ->execute(array_values($row));
        }
    }

    // ── off until finalized ─────────────────────────────────────────────────────

    public function testANewActivationDeclarationOverAnEnabledEngineReadsOffUntilFinalized(): void
    {
        $fresh = self::bootAppWithConfigOverride('render', [])->getContainer();
        self::assertFalse($fresh->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'), 'no stored state: off');
        self::assertSame(0, (int) $this->connection()->getPDO()
            ->query("SELECT count(*) FROM block_types WHERE slug = 'product-grid'")->fetchColumn());

        $store = $this->container()->get(ActivationStore::class);
        $gen = $store->startOrJoin('thallo.commerce', 'test')->generation;
        $this->runner()->run('thallo.commerce', $gen, false);
        $this->runner()->run('thallo.commerce', $gen, true);
        self::assertTrue($this->storedValue('thallo.commerce'));
        $after = self::bootAppWithConfigOverride('render', [])->getContainer();
        self::assertTrue($after->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'), 'on after it finalized');
    }

    public function testASimpleCapabilityStillFollowsItsEngine(): void
    {
        $this->connection()->getPDO()
            ->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.importers.enabled'");
        $fresh = self::bootAppWithConfigOverride('render', [])->getContainer();
        self::assertTrue($fresh->get(CapabilityRegistry::class)->isEnabled('thallo.importers'));
    }

    public function testConfigurationTrueNeverMakesAnActivationCapabilityEffective(): void
    {
        $fresh = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => true]])
            ->getContainer();
        self::assertFalse($fresh->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'));
    }

    // ── adoption ────────────────────────────────────────────────────────────────

    public function testCommerceEffectiveBeforeTheUpgradeIsOnAfterIt(): void
    {
        $adopted = $this->upgrade();
        self::assertContains('thallo.commerce', $adopted);
        self::assertTrue($this->storedValue('thallo.commerce'));
        $record = $this->container()->get(ActivationStore::class)->find('thallo.commerce');
        self::assertSame(ActivationStatus::SUCCEEDED, $record->status);
        self::assertTrue($record->result['adopted'] ?? false);
    }

    public function testAConfigurationOffIsNotAdopted(): void
    {
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]])
            ->getContainer();
        $adopted = $this->upgrade($off);
        self::assertNotContains('thallo.commerce', $adopted);
        self::assertNull($this->storedValue('thallo.commerce'));
        $record = $this->container()->get(ActivationStore::class)->find('thallo.commerce');
        self::assertSame(ActivationStatus::IDLE, $record->status);
    }

    public function testAConfigurationValueOtherThanTrueIsReadAsTheOldRuleDid(): void
    {
        // The old rule read a configured value as on only when it was exactly true: a string from
        // env() ('true', '0') meant off, so it isn't adopted either.
        $string = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => 'true']])
            ->getContainer();
        $adopted = $this->upgrade($string);
        self::assertNotContains('thallo.commerce', $adopted);
        self::assertNull($this->storedValue('thallo.commerce'));
    }

    public function testAStoredOffAndAnOpenActivationAreUntouched(): void
    {
        $this->container()->get(CapabilityStateStore::class)->put('thallo.commerce', false);
        $store = $this->container()->get(ActivationStore::class);
        $store->startOrJoin('thallo.subscriptions', 'test');                  // open, and stores off…
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.subscriptions.enabled'"
        );                                                       // …so remove it: no state, open activation
        $adopted = $this->upgrade();
        self::assertNotContains('thallo.commerce', $adopted);
        self::assertNotContains('thallo.subscriptions', $adopted);
        self::assertFalse($this->storedValue('thallo.commerce'));
        self::assertNull($this->storedValue('thallo.subscriptions'));
        self::assertSame(ActivationStatus::PREPARING, $store->find('thallo.subscriptions')->status);
    }

    public function testPaymentsIsAdoptedWhenPayviaIsEnabledAndReady(): void
    {
        self::assertContains('thallo.payments', $this->upgrade());
        self::assertTrue($this->storedValue('thallo.payments'));
    }

    public function testPaymentsIsNotAdoptedWhenPayviaSchemaIsPending(): void
    {
        $receipts = $this->makePayviaPending();
        try {
            $adopted = $this->upgrade();
        } finally {
            $this->restoreReceipts($receipts);
        }
        self::assertNotContains('thallo.payments', $adopted);
        self::assertNull($this->storedValue('thallo.payments'));
    }

    public function testUpgradeThenOffThenProvisionStaysOff(): void
    {
        $this->upgrade();
        $this->container()->get(CapabilityStateStore::class)->put('thallo.payments', false);
        $this->connection()->getPDO()->exec('DELETE FROM thallo_capability_adoption');   // even if the record is lost
        $this->upgrade();
        $this->container()->get(CapabilityProvisioning::class)->syncRows();
        self::assertFalse($this->storedValue('thallo.payments'));
    }

    public function testAdoptionRunsOnceAndCreatesItsRowsThroughTheInitializer(): void
    {
        $store = $this->container()->get(ActivationStore::class);
        self::assertNull($store->find('thallo.payments'), 'precondition: Payments has no row');
        $this->upgrade();
        self::assertSame(ActivationStatus::SUCCEEDED, $store->find('thallo.payments')->status);
        $record = $this->connection()->getPDO()->query('SELECT state, eligible FROM thallo_capability_adoption')
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('done', $record['state']);

        $this->container()->get(CapabilityStateStore::class)->put('thallo.commerce', false);
        self::assertSame([], $this->upgrade(), 'done: a second run adopts nothing');
        self::assertFalse($this->storedValue('thallo.commerce'));
    }

    public function testAdoptionAdvancesTheStateVersionOncePerAdoption(): void
    {
        $version = $this->container()->get(CapabilityStateVersion::class);
        $before = (int) $version->current();
        $adopted = $this->upgrade();
        self::assertSame($before + count($adopted), (int) $version->current());
        $this->upgrade();
        self::assertSame($before + count($adopted), (int) $version->current(), 'a second run changes nothing');
    }

    public function testAdoptionReadsAndChangesOnlyTheTargetDatabase(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DROP SCHEMA IF EXISTS adoption_target CASCADE');
        $pdo->exec('CREATE SCHEMA adoption_target');
        $tables = ['thallo_system_flags', 'capability_activations', 'capability_activation_events', 'migrations'];
        foreach ($tables as $table) {
            $pdo->exec("CREATE TABLE adoption_target.{$table} (LIKE public.{$table} INCLUDING ALL)");
        }
        $pdo->exec('INSERT INTO adoption_target.migrations SELECT * FROM public.migrations');
        // A (public) holds a contrary state: Commerce stored off there.
        $this->container()->get(CapabilityStateStore::class)->put('thallo.commerce', false);
        $aFlags = $this->rows('SELECT key, value FROM public.thallo_system_flags ORDER BY key');
        $aRows = $this->rows('SELECT * FROM public.capability_activations ORDER BY capability');
        try {
            $adopted = $this->upgrade(null, $this->target('adoption_target'));
            self::assertContains('thallo.commerce', $adopted, 'B had no stored state');
            self::assertSame('true', $this->scalar(
                "SELECT value FROM adoption_target.thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'"
            ));
            self::assertSame('1', $this->scalar(
                "SELECT count(*) FROM adoption_target.capability_activations WHERE status = 'succeeded'"
                . " AND capability = 'thallo.commerce'"
            ));
            self::assertSame('done', $this->scalar('SELECT state FROM adoption_target.thallo_capability_adoption'));
            self::assertSame($aFlags, $this->rows('SELECT key, value FROM public.thallo_system_flags ORDER BY key'));
            self::assertSame($aRows, $this->rows('SELECT * FROM public.capability_activations ORDER BY capability'));
            self::assertSame('0', $this->scalar('SELECT count(*) FROM public.thallo_capability_adoption'));
        } finally {
            $pdo->exec('DROP SCHEMA IF EXISTS adoption_target CASCADE');
        }
    }

    public function testOverlappingCapturesKeepTheFirstCommittedDecision(): void
    {
        // No table at the start.
        $this->connection()->getPDO()->exec('DROP TABLE IF EXISTS thallo_capability_adoption');
        try {
            $a = $this->startChild('adoption_capture_child.php', ['--pause-before-insert']);
            $a->waitFor('evaluated');                                     // Commerce eligible in A's evaluation
            $this->container()->get(CapabilityStateStore::class)->put('thallo.commerce', false);   // now it isn't
            $b = $this->startChild('adoption_capture_child.php');
            $bOut = $b->finish();
            self::assertStringContainsString('eligible=', $bOut);
            self::assertStringNotContainsString('thallo.commerce', $bOut, 'B evaluated after the stored off');
            $a->signal('resume');
            $aOut = $a->finish();
            $why = 'A reports the first committed capture';
            self::assertSame($this->eligibleLine($bOut), $this->eligibleLine($aOut), $why);

            $this->upgrade();                                              // done (capture reuses B's record)
            $c = $this->startChild('adoption_capture_child.php');
            self::assertStringContainsString('state=done', $c->finish(), 'a later capture leaves done untouched');
        } finally {
            $this->connection()->getPDO()->exec(
                'CREATE TABLE IF NOT EXISTS thallo_capability_adoption (id smallint PRIMARY KEY CHECK (id = 1),'
                . " state text NOT NULL CHECK (state IN ('captured','done')), eligible jsonb NOT NULL,"
                . ' captured_at timestamptz NOT NULL)'
            );
        }
    }

    private function scalar(string $sql): string
    {
        return (string) $this->connection()->getPDO()->query($sql)->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->connection()->getPDO()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function eligibleLine(string $out): string
    {
        preg_match('/eligible=\S*/', $out, $m);
        return $m[0] ?? '';
    }
}
