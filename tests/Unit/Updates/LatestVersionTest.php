<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updates\LatestVersion;

/**
 * The version an install may move to: the newest published version strictly greater than the
 * installed one under Composer's semver. A pre-release install may move to a newer pre-release
 * or to stable; a stable install is never pointed at a pre-release. Branches never count.
 */
final class LatestVersionTest extends TestCase
{
    public function testPicksTheNewestStrictlyGreaterVersion(): void
    {
        self::assertSame(
            '1.0.0-beta.22',
            LatestVersion::pick('1.0.0-beta.21', ['v1.0.0-beta.22', 'v1.0.0-beta.21', 'v1.0.0-beta.20']),
        );
    }

    public function testAPreReleaseInstallMayMoveToStable(): void
    {
        self::assertSame('1.0.0', LatestVersion::pick('1.0.0-beta.21', ['v1.0.0', 'v1.0.0-beta.22']));
    }

    public function testAStableInstallIgnoresPreReleases(): void
    {
        self::assertNull(LatestVersion::pick('1.0.0', ['v1.1.0-beta.1', 'v1.0.0']));
        self::assertSame('1.0.1', LatestVersion::pick('1.0.0', ['v1.0.1', 'v1.1.0-beta.1']));
    }

    public function testDevBranchesOlderVersionsAndGarbageAreIgnored(): void
    {
        self::assertNull(LatestVersion::pick('1.0.0-beta.21', ['dev-main', 'v1.0.0-beta.20', 'not-a-version']));
    }

    public function testTheInstalledVersionMayCarryALeadingV(): void
    {
        self::assertSame('1.0.0-beta.22', LatestVersion::pick('v1.0.0-beta.21', ['v1.0.0-beta.22']));
    }
}
