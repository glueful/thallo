<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Retention\PruneReport;
use PHPUnit\Framework\TestCase;

final class PruneReportTest extends TestCase
{
    public function testRecordLineageAccumulatesCounts(): void
    {
        $report = new PruneReport();

        $report->recordLineage(deleted: 2, retained: 3, pinnedSkipped: 1);
        $report->recordLineage(deleted: 4, retained: 0, pinnedSkipped: 1);

        self::assertSame(2, $report->lineagesScanned);
        self::assertSame(6, $report->versionsDeleted);
        self::assertSame(3, $report->versionsRetained);
        self::assertSame(2, $report->pinnedSkipped);
        self::assertSame([
            'lineages_scanned' => 2,
            'versions_deleted' => 6,
            'versions_retained' => 3,
            'pinned_skipped' => 2,
        ], $report->toArray());
    }
}
