<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Purge\PurgeRunRepository;

/**
 * Honest `purging` tags (workspaces UX): {@see PurgeRunRepository::isStalled()} is the ONE
 * predicate deciding when a purging workspace additionally reports "needs operator attention"
 * — dispatch never landed, the job failed, a worker died mid-run, or the run has sat `queued`
 * untouched past the grace window (no queue worker consuming — the dev-install classic that
 * motivated this: a purge stuck at `queued`/attempts 0 forever behind a greyed tag).
 */
final class PurgeStallReportingTest extends AppTestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function runs(): array
    {
        $now = new \DateTimeImmutable('2026-07-25 12:00:00');
        $fresh = $now->modify('-30 seconds')->format('Y-m-d H:i:s');
        $old = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        $leaseLive = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
        $leaseDead = $now->modify('-1 minute')->format('Y-m-d H:i:s');

        return [
            'requested never dispatched' => [['status' => 'requested', 'created_at' => $fresh], true],
            'dispatch failed' => [['status' => 'dispatch_failed', 'created_at' => $old], true],
            'job failed' => [['status' => 'failed', 'attempts' => 2, 'created_at' => $old], true],
            'queued fresh (worker may pick it)' => [
                ['status' => 'queued', 'attempts' => 0, 'created_at' => $fresh],
                false,
            ],
            'queued past the grace window, never attempted (no worker)' => [
                ['status' => 'queued', 'attempts' => 0, 'created_at' => $old],
                true,
            ],
            'queued old but already attempted (retry backoff, not stalled)' => [
                ['status' => 'queued', 'attempts' => 1, 'created_at' => $old],
                false,
            ],
            'running with a live lease' => [
                ['status' => 'running', 'attempts' => 1, 'lease_expires_at' => $leaseLive, 'created_at' => $old],
                false,
            ],
            'running with a dead lease (worker died)' => [
                ['status' => 'running', 'attempts' => 1, 'lease_expires_at' => $leaseDead, 'created_at' => $old],
                true,
            ],
            'queued with a dead lease from a previous claim' => [
                ['status' => 'queued', 'attempts' => 1, 'lease_expires_at' => $leaseDead, 'created_at' => $old],
                true,
            ],
        ];
    }

    /**
     * @dataProvider runs
     * @param array<string, mixed> $run
     */
    public function testStallPredicate(array $run, bool $expected): void
    {
        $now = new \DateTimeImmutable('2026-07-25 12:00:00');
        self::assertSame($expected, PurgeRunRepository::isStalled($run, $now));
    }

    public function testCompletedRunsAreNeverStalled(): void
    {
        self::assertFalse(PurgeRunRepository::isStalled(['status' => 'completed']));
    }

    /**
     * Disable-gate policy revision (2026-07-25, sp2c §6 note): `customized` starters are a
     * fully KNOWN state and no longer block workspace disable — only genuinely incoherent
     * provenance (orphaned sources here) still does. Asserted against the REAL coverage
     * check by planting provenance rows for definitions that actually exist.
     */
    public function testCustomizedStartersDoNotBlockDisableButOrphanedSourcesDo(): void
    {
        $check = $this->container()->get(\App\Content\Starter\DefaultStarterCoverageCheck::class);
        $pdo = $this->connection()->getPDO();

        // Self-contained: plant one row per divergent state (this DB carries no synced
        // provenance of its own) and assert exactly which one surfaces.
        $pdo->exec(
            "INSERT INTO starter_provenance "
            . "(uuid, tenant_uuid, definition_kind, definition_key, source_id, fingerprint, state) VALUES "
            . "('stalltstcus1', '', 'block_type', 'stall_test_cus', 'stall:test-customized', 'fp1', 'customized'), "
            . "('stalltstorp1', '', 'block_type', 'stall_test_orp', 'stall:test-orphaned', 'fp2', 'orphaned_source')"
        );
        try {
            $violations = $check->coverageViolations();
            $customized = array_values(array_filter(
                $violations,
                fn (string $v): bool => str_contains($v, 'stall_test_cus'),
            ));
            self::assertSame([], $customized, 'customized rows must never surface as violations');

            $orphaned = array_values(array_filter(
                $violations,
                fn (string $v): bool => str_contains($v, 'stall_test_orp'),
            ));
            self::assertSame(['block_type:stall_test_orp is orphaned_source'], $orphaned);
        } finally {
            $pdo->exec("DELETE FROM starter_provenance WHERE uuid IN ('stalltstcus1', 'stalltstorp1')");
        }
    }
}
