<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Content\Starter\RawPdoWriteAudit;
use PHPUnit\Framework\TestCase;

final class RawPdoWriteAuditTest extends TestCase
{
    public function testRealSourceTreeHasNoViolations(): void
    {
        $report = (new RawPdoWriteAudit(dirname(__DIR__, 3)))->run();
        self::assertTrue($report['available']);
        self::assertSame([], $report['unclassified']);
        self::assertSame([], $report['bucketViolations']);
        self::assertSame([], $report['wrapperMismatches']);
    }

    public function testPackagedDeploymentWithoutSourcesReportsUnavailable(): void
    {
        $directory = sys_get_temp_dir() . '/thallo-audit-' . bin2hex(random_bytes(5));
        mkdir($directory);
        try {
            $report = (new RawPdoWriteAudit($directory))->run();
            self::assertFalse($report['available']);
            self::assertSame([], $report['unclassified']);
        } finally {
            rmdir($directory);
        }
    }
}
