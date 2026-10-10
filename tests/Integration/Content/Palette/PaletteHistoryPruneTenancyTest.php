<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Content\Console\PrunePaletteHistoryCommand;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Under real tenancy enforcement (custom palette spec §5.3): the nightly prune runs in each
 * workspace, and raises each workspace's history horizon from that workspace's own jobs — palette
 * generations are per workspace, so another store's numbers must never reach it. Opt-in:
 * THALLO_TENANCY_DEV_LINK=1.
 */
final class PaletteHistoryPruneTenancyTest extends RetrofittedTenantTestCase
{
    private function seed(string $tenant, int $generation): void
    {
        $this->runAsTenant($tenant, function () use ($generation): void {
            $this->container()->get(PaletteState::class)->ensureRow();
            $db = $this->connection();
            $db->table('palette_state')->where('site', '=', 'site')->update(['generation' => $generation]);
            $old = gmdate('Y-m-d H:i:s', time() - 91 * 86400);
            $db->table('palette_jobs')->insert([
                'id' => substr(md5((string) $generation), 0, 12), 'slot' => 1, 'to_token' => 'color.accent',
                'contrast_to_token' => 'color.accent-contrast', 'status' => 'completed', 'passes' => 1,
                'work_items_total' => 0, 'work_items_done' => 0, 'work_items_failed' => 0,
                'failure_report' => '[]', 'completed_generation' => $generation, 'created_at' => $old,
                'heartbeat_at' => $old, 'finished_at' => $old,
            ]);
        });
    }

    private function horizon(string $tenant): int
    {
        return (int) $this->runAsTenant($tenant, fn () => $this->connection()->table('palette_state')
            ->where('site', '=', 'site')->first()['history_horizon'] ?? 0);
    }

    public function testThePruneRaisesEachWorkspacesHorizonFromItsOwnJobsOnly(): void
    {
        $this->seed(self::$tenantAUuid, 7);
        $this->seed(self::$tenantBUuid, 2);
        $this->runAsSystem(function (): void {
            $tester = new CommandTester($this->container()->get(PrunePaletteHistoryCommand::class));
            self::assertSame(0, $tester->execute([]));
        });
        self::assertSame(7, $this->horizon(self::$tenantAUuid));
        self::assertSame(2, $this->horizon(self::$tenantBUuid), 'never above its own generation');
    }
}
