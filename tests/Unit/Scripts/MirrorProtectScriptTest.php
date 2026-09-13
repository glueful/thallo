<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** scripts/mirror-protect plans rulesets and feature switches for all 15 mirrors and never runs without a token. */
final class MirrorProtectScriptTest extends TestCase
{
    public function testDryRunPlansEveryMirrorAndTheTokenIsRequiredOtherwise(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/mirror-protect") . ' --dry-run 2>&1', $lines, $code);
        $out = implode("\n", $lines);

        self::assertSame(0, $code, $out);
        self::assertSame(15, substr_count($out, '== glueful/thallo'), '15 mirrors');
        self::assertSame(15, substr_count($out, 'read-only mirror: default branch'));
        self::assertSame(15, substr_count($out, 'read-only mirror: tags'));
        self::assertSame(15, substr_count($out, '"has_issues":false'));
        self::assertStringContainsString('"actor_type":"RepositoryRole"', $out, 'the admin bypasses');

        exec('env -u GITHUB_TOKEN bash ' . escapeshellarg("$root/scripts/mirror-protect") . ' 2>&1', $bad, $badCode);
        self::assertSame(2, $badCode);
    }
}
