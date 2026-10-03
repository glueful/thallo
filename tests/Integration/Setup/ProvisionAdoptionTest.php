<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Core\Tests\Support\CapabilityBaseline;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\InstallOptions;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Setup\CapabilityAdoptionCaptureFailed;
use Thallo\Core\Setup\Console\ProvisionCommand;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\FakeProvisionInstaller;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;

/**
 * Provision's schema-changing sequence (spec §7.3a): capture upgrade-adoption eligibility on the
 * target database, run the installer (migrations), then adopt — through ProvisionCommand's own
 * installWithAdoption(), with a fake installer so no .env is written and no real install runs.
 * Eligibility never comes from a schema the same provision made ready, and an interrupted provision
 * keeps its capture.
 */
final class ProvisionAdoptionTest extends AppTestCase
{
    use ResetsCommerceActivation;

    /** @var list<array<string, mixed>> */
    private array $removedReceipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->restoreReceipts();
        $this->clear();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DROP SCHEMA IF EXISTS fresh_target CASCADE');
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    private function clear(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "DELETE FROM thallo_system_flags WHERE key IN ('capability.thallo.commerce.enabled',"
            . " 'capability.thallo.subscriptions.enabled', 'capability.thallo.payments.enabled')"
        );
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability = 'thallo.payments'");
        $pdo->exec("DELETE FROM capability_activations WHERE capability = 'thallo.payments'");
        $pdo->exec(
            "UPDATE capability_activations SET generation = 0, status = 'idle', steps_done = '[]', owner_token = NULL,
               lease_expires_at = NULL, workspaces = '{}', result = '{}' WHERE capability = 'thallo.subscriptions'"
        );
        $this->resetCommerceActivation();
        $pdo->exec('DELETE FROM thallo_capability_adoption');
    }

    private function target(?string $schema = null, ?int $port = null): DatabaseConfig
    {
        return new DatabaseConfig(
            'pgsql',
            (string) env('DB_PGSQL_HOST', '127.0.0.1'),
            $port ?? (int) env('DB_PGSQL_PORT', '5432'),
            (string) env('DB_PGSQL_DATABASE', 'app_test'),
            (string) env('DB_PGSQL_USERNAME', 'postgres'),
            (string) env('DB_PGSQL_PASSWORD', ''),
            $schema ?? (string) env('DB_PGSQL_SCHEMA', 'public'),
        );
    }

    private function provision(FakeProvisionInstaller $installer): ProvisionCommand
    {
        return new ProvisionCommand($this->container(), $this->appContext(), null, $installer);
    }

    private function install(FakeProvisionInstaller $installer, DatabaseConfig $target): mixed
    {
        return $this->provision($installer)->installWithAdoption(
            base_path($this->appContext()),
            new InstallOptions(database: $target),
        );
    }

    private function makePayviaPending(): void
    {
        $pdo = $this->connection()->getPDO();
        $row = $pdo->query("SELECT * FROM migrations WHERE source = 'glueful/payvia' ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $pdo->prepare('DELETE FROM migrations WHERE id = ?')->execute([$row['id']]);
        $this->removedReceipts[] = $row;
    }

    private function restoreReceipts(): void
    {
        foreach ($this->removedReceipts as $row) {
            $columns = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            $this->connection()->getPDO()->prepare("INSERT INTO migrations ({$columns}) VALUES ({$marks})")
                ->execute(array_values($row));
        }
        $this->removedReceipts = [];
    }

    private function stored(string $id): ?string
    {
        $stmt = $this->connection()->getPDO()->prepare('SELECT value FROM thallo_system_flags WHERE key = ?');
        $stmt->execute(["capability.{$id}.enabled"]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function record(string $schema = 'public'): array
    {
        $row = $this->connection()->getPDO()->query("SELECT state, eligible FROM {$schema}.thallo_capability_adoption")
            ->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        return ['state' => $row['state'], 'eligible' => json_decode((string) $row['eligible'], true)];
    }

    public function testPayviaReadyOnlyDuringProvisionIsNotAdopted(): void
    {
        $this->makePayviaPending();
        $result = $this->install(new FakeProvisionInstaller(fn () => $this->restoreReceipts()), $this->target());
        self::assertTrue($result->ok);
        self::assertNull($this->stored('thallo.payments'), 'Payvia was pending when eligibility was captured');
        self::assertSame('true', $this->stored('thallo.commerce'));
        self::assertSame('done', $this->record()['state']);
    }

    public function testARetryAfterMigrationsButBeforeAdoptionAppliesTheCapturedList(): void
    {
        $this->makePayviaPending();
        $installer = new FakeProvisionInstaller(fn () => $this->restoreReceipts(), 'failed');
        $failed = $this->install($installer, $this->target());
        self::assertFalse($failed->ok);
        self::assertSame('captured', $this->record()['state']);
        self::assertNull($this->stored('thallo.commerce'), 'nothing adopted before the migrations completed');

        $retry = $this->install(new FakeProvisionInstaller(), $this->target());     // Payvia is ready now
        self::assertTrue($retry->ok);
        self::assertSame('done', $this->record()['state']);
        self::assertNull($this->stored('thallo.payments'), 'the retry applied the list captured first');
        self::assertSame('true', $this->stored('thallo.commerce'));
    }

    public function testAFreshInstallCapturesNothing(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('CREATE SCHEMA fresh_target');
        $installer = new FakeProvisionInstaller(fn () => $this->migrateFresh());
        $result = $this->install($installer, $this->target('fresh_target'));
        self::assertTrue($result->ok);
        self::assertSame(['state' => 'done', 'eligible' => []], $this->record('fresh_target'));
        self::assertSame(0, $this->freshSwitches());
    }

    public function testAFreshInstallThatCrashesAfterItsMigrationsStillAdoptsNothing(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('CREATE SCHEMA fresh_target');
        try {
            $crash = new FakeProvisionInstaller(fn () => $this->migrateFresh(), 'crash');
            $this->install($crash, $this->target('fresh_target'));
            self::fail('the crash propagates');
        } catch (\RuntimeException) {
        }
        self::assertSame(['state' => 'captured', 'eligible' => []], $this->record('fresh_target'));
        // The retry finds engines that look ready now (the tables exist), and adopts nothing.
        $this->install(new FakeProvisionInstaller(), $this->target('fresh_target'));
        self::assertSame(['state' => 'done', 'eligible' => []], $this->record('fresh_target'));
        self::assertSame(0, $this->freshSwitches());
    }

    public function testAnUnreachableDatabaseStopsProvisionBeforeItMigrates(): void
    {
        $installer = new FakeProvisionInstaller();
        try {
            $this->install($installer, $this->target(null, 1));
            self::fail('an uninspectable database was taken for nothing to adopt');
        } catch (CapabilityAdoptionCaptureFailed) {
        }
        self::assertSame(0, $installer->calls, 'the installer never ran');
        self::assertSame([], $this->record());
    }

    public function testAReadinessCheckThatFailsStopsProvisionInsteadOfRecordingNothing(): void
    {
        // An existing install (it has the system table) whose migrations ledger can't be read: the
        // engines' readiness is unknown, which is not "not eligible".
        $pdo = $this->connection()->getPDO();
        $pdo->exec('CREATE SCHEMA fresh_target');
        $pdo->exec('CREATE TABLE fresh_target.thallo_system_flags (LIKE public.thallo_system_flags INCLUDING ALL)');
        $pdo->exec('CREATE TABLE fresh_target.migrations (id integer)');     // a ledger it can't read
        $installer = new FakeProvisionInstaller();
        try {
            $this->install($installer, $this->target('fresh_target'));
            self::fail('an unknown readiness was recorded as nothing to adopt');
        } catch (CapabilityAdoptionCaptureFailed) {
        }
        self::assertSame(0, $installer->calls, 'the installer never ran');
        self::assertSame([], $this->record('fresh_target'), 'nothing was recorded');
    }

    public function testAnAdoptionThatFailsAfterTheMigrationsIsReportedAndCanBeRetried(): void
    {
        // An upgraded install (system table, a ready ledger) where Commerce is eligible, but whose
        // activation table is missing: adopting fails after the migrations ran.
        $pdo = $this->connection()->getPDO();
        $pdo->exec('CREATE SCHEMA fresh_target');
        foreach (['thallo_system_flags', 'migrations'] as $table) {
            $pdo->exec("CREATE TABLE fresh_target.{$table} (LIKE public.{$table} INCLUDING ALL)");
        }
        $pdo->exec('INSERT INTO fresh_target.migrations SELECT * FROM public.migrations');

        $command = $this->provision(new FakeProvisionInstaller());
        $result = $command->installWithAdoption(
            base_path($this->appContext()),
            new InstallOptions(database: $this->target('fresh_target')),
        );
        self::assertTrue($result->ok, 'the migrations stand');
        self::assertStringContainsString('thallo:provision', (string) $command->adoptionFailure());
        self::assertSame('captured', $this->record('fresh_target')['state'] ?? null, 'a retry adopts it');
    }

    private function freshSwitches(): int
    {
        return (int) $this->connection()->getPDO()
            ->query("SELECT count(*) FROM fresh_target.thallo_system_flags WHERE key LIKE 'capability.%.enabled'")
            ->fetchColumn();
    }

    /** What the migrations of a fresh install leave: the system tables, ledger included. */
    private function migrateFresh(): void
    {
        $pdo = $this->connection()->getPDO();
        $tables = ['thallo_system_flags', 'capability_activations', 'capability_activation_events', 'migrations'];
        foreach ($tables as $table) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS fresh_target.{$table} (LIKE public.{$table} INCLUDING ALL)");
        }
        $pdo->exec('INSERT INTO fresh_target.migrations SELECT * FROM public.migrations ON CONFLICT DO NOTHING');
    }
}
