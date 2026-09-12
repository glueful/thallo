<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** scripts/release-split publishes the split: 15 subtree splits, one tag each, 15 pushes — never on its own. */
final class ReleaseSplitScriptTest extends TestCase
{
    public function testDryRunListsEveryArtifactAndRefusesANonTag(): void
    {
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/release-split") . ' --dry-run v1.0.0-beta.21 2>&1', $lines, $code);
        $out = implode("\n", $lines);

        self::assertSame(0, $code, $out);
        self::assertSame(15, substr_count($out, 'git subtree split'), 'core + skeleton + 13 packs');
        self::assertStringContainsString('--prefix=core', $out);
        self::assertStringContainsString('--prefix=skeleton', $out);
        self::assertStringContainsString('--prefix=packages/thallo-contracts', $out);
        self::assertSame(15, substr_count($out, 'git push split/'));
        self::assertStringContainsString('refs/tags/v1.0.0-beta.21', $out, 'each mirror receives the release tag');
        self::assertStringContainsString('"glueful/thallo-core": "^1.0.0-beta.21"', $out, 'the skeleton pin is bumped');

        exec('bash ' . escapeshellarg("$root/scripts/release-split") . ' --dry-run main 2>&1', $bad, $badCode);
        self::assertSame(2, $badCode);
    }
}
