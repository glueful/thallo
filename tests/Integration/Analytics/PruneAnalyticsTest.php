<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Thallo\Analytics\Console\PruneAnalyticsCommand;

final class PruneAnalyticsTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    public function testPruneRemovesAgedFactsAndKeepsRollups(): void
    {
        // One old fact (100 days ago) + one recent.
        $old = gmdate('Y-m-d H:i:s', time() - 100 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        foreach ([$old, $recent] as $ts) {
            $this->connection()->table('analytics_facts')->insert([
                'occurred_at' => $ts, 'event' => 'auth.login', 'category' => 'auth',
                'subject_type' => 'user', 'subject_id' => 'u-1', 'actor_type' => 'user',
                'actor_id' => 'u-1', 'metadata' => null,
            ]);
        }
        $this->connection()->table('analytics_daily')->insert([
            'day' => gmdate('Y-m-d', time() - 100 * 86400), 'event' => 'auth.login',
            'subject' => '__total__', 'count' => 5,
        ]);

        $this->connection()->table('analytics_active_actors')->insert([
            'day' => gmdate('Y-m-d', time() - 1 * 86400),
            'metric' => 'active_users',
            'actor_type' => 'user',
            'actor_id_hash' => 'probe-hash',
        ]);

        $command = $this->container()->get(PruneAnalyticsCommand::class);
        $deleted = $command->prune(90); // retention days

        self::assertSame(1, $deleted);
        self::assertSame(1, (int) $this->connection()->table('analytics_facts')->count());
        self::assertSame(1, (int) $this->connection()->table('analytics_daily')->count(), 'rollups kept');
        self::assertSame(
            1,
            (int) $this->connection()->table('analytics_active_actors')->count(),
            'active_actors row preserved'
        );
    }
}
