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

        self::assertStringContainsString('must_contain "core/resources/admin/index.html"', $verify);
        self::assertStringContainsString('core/resources/admin/assets', $verify);
        self::assertStringContainsString(
            'must_not_contain_prefix "public/admin/"',
            $verify,
            'the published copy never ships',
        );
        self::assertStringContainsString('git add -f core/resources/admin', $bake);
        self::assertStringNotContainsString('add -f public/admin', $bake);
    }

    public function testDailyBuildsAndPublishedCopiesStayOutOfGit(): void
    {
        $ignore = (string) file_get_contents(dirname(__DIR__, 3) . '/.gitignore');
        self::assertStringContainsString("/core/resources/admin/\n", $ignore);
        self::assertStringContainsString("/public/admin/\n", $ignore);
    }
}
