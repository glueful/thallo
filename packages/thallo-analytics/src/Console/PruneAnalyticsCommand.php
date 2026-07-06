<?php

declare(strict_types=1);

namespace Thallo\Analytics\Console;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'analytics:prune', description: 'Delete raw analytics_facts past the retention window.')]
final class PruneAnalyticsCommand extends BaseCommand
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    /** Delete facts older than $days; returns the row count removed. Rollups are never touched. */
    public function prune(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        return (int) $this->connection->table('analytics_facts')
            ->where('occurred_at', '<', $cutoff)
            ->delete();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) config($this->getContext(), 'analytics.retention_days', 90);
        $deleted = $this->prune($days);
        $this->success(sprintf('Pruned %d analytics fact(s) older than %d days.', $deleted, $days));
        return self::SUCCESS;
    }
}
