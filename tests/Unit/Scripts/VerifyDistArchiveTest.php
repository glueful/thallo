<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** The release scripts bake and check the admin bundle where it now lives: core/resources/admin. */
final class VerifyDistArchiveTest extends TestCase
{
    public function testTheReleaseScriptsBakeAndCheckTheBundleUnderCore(): void
    {
        $root = dirname(__DIR__, 3);
        $verify = (string) file_get_contents("$root/scripts/verify-dist-archive");
        $bake = (string) file_get_contents("$root/scripts/release-bake");

        // Per-artifact checks: the core archive carries the bundle; the skeleton never does.
        self::assertStringContainsString('"resources/admin/index.html"', $verify);
        self::assertStringContainsString('core/resources/admin/assets', $verify);
        self::assertStringContainsString('public/admin/; do', $verify, 'the published copy never ships');
        self::assertStringContainsString('git add -f core/resources/admin', $bake);
        self::assertStringNotContainsString('add -f public/admin', $bake);
    }

    public function testEveryArtifactIsCheckedFromTheReleaseCommit(): void
    {
        // Runs against HEAD:<dir> — no split branch needed. Tests never bake, so the bundle
        // check may fail; everything structural must pass and the script must name all 15.
        $root = dirname(__DIR__, 3);
        exec('bash ' . escapeshellarg("$root/scripts/verify-dist-archive") . ' 2>&1', $lines);
        $out = implode("\n", $lines);
        self::assertStringContainsString('ok: skeleton ships the operator\'s tree only', $out);
        self::assertSame(13, substr_count($out, 'ships composer.json + src/'), $out);
        self::assertStringNotContainsString('FAIL: skeleton', $out);
        self::assertStringNotContainsString('lacks composer.json or src/', $out);
    }

    public function testDailyBuildsAndPublishedCopiesStayOutOfGit(): void
    {
        $ignore = (string) file_get_contents(dirname(__DIR__, 3) . '/.gitignore');
        self::assertStringContainsString("/core/resources/admin/\n", $ignore);
        self::assertStringContainsString("/public/admin/\n", $ignore);
    }
}
