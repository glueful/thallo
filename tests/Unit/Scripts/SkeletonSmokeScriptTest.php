<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** scripts/skeleton-smoke installs skeleton/ the way an operator will, from the LOCAL packages. */
final class SkeletonSmokeScriptTest extends TestCase
{
    public function testDryRunPrintsTheInstallStepsAndTouchesNothing(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/skeleton-smoke") . ' --dry-run 2>&1', $lines, $code);
        $out = implode("\n", $lines);

        self::assertSame(0, $code, $out);
        foreach (
            [
                'skeleton/.',            // the template is copied, never installed from Packagist here
                'repositories',          // path repositories for core/ and packages/* are injected
                'composer install --no-dev',
                'thallo:doctor',
                'thallo:provision --no-interaction',
                'migrate:status',
                'public/admin/index.html',
                'docs/openapi.json',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $out, "dry run must show: {$needle}");
        }
    }
}
