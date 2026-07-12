<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enablement;

use PHPUnit\Framework\TestCase;

/**
 * Release-gate invariant (SP1 Task 21): at release, glueful/tenancy is a PUBLISHED package,
 * not a sibling path repository. Production Composer must never depend on local repo layout;
 * the identity/provisioning plane is bundled for clean setup while persisted runtime flags
 * still control tenant enforcement.
 */
final class TenancyReleaseDistributionTest extends TestCase
{
    public function testTenancyIsPublishedNotAPathRepoAtRelease(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        // No sibling path repository for tenancy.
        foreach (($composer['repositories'] ?? []) as $repo) {
            self::assertStringNotContainsString('extensions/tenancy', (string) ($repo['url'] ?? ''));
        }

        // Production pins a real published version constraint, not '*' against a path repo.
        $constraint = $composer['require']['glueful/tenancy'] ?? null;
        self::assertNotNull($constraint, 'glueful/tenancy is bundled for the identity plane');
        self::assertNotSame('*', $constraint, 'pin a published version at release');
        self::assertArrayNotHasKey('glueful/tenancy', $composer['require-dev'] ?? []);
    }
}
