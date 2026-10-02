<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use Thallo\Core\Setup\Console\ProvisionCommand;
use Thallo\Core\Setup\Doctor\Check;
use Thallo\Core\Setup\Doctor\Doctor;
use PHPUnit\Framework\TestCase;

/**
 * After a successful provision the operator's next job is the first admin. The browser flow at
 * /admin/setup is the primary option (it is what a CMS operator expects); the CLI command is the
 * scripted alternative. Both are printed, in that order, with the real public origin.
 */
final class ProvisionNextStepsTest extends TestCase
{
    public function testWebSetupComesFirstWithThePublicOriginThenTheCliCommand(): void
    {
        $lines = ProvisionCommand::nextSteps('https://thallo.dev/');

        self::assertCount(2, $lines);
        self::assertStringContainsString(
            'https://thallo.dev/admin/setup',
            $lines[0],
            'web setup first, trailing slash trimmed',
        );
        self::assertStringContainsString('thallo:create-admin', $lines[1], 'CLI alternative second');
        self::assertStringNotContainsString('thallo.dev//', $lines[0]);
    }

    public function testFallsBackToTheLocalQuickstartOriginWhenBaseUrlIsUnset(): void
    {
        $lines = ProvisionCommand::nextSteps(null);

        self::assertStringContainsString('http://localhost:8000/admin/setup', $lines[0]);
    }

    public function testTheChecksThatNeedTheSiteRunAgainAtTheEndAndTheirWarningsAreShown(): void
    {
        // On a fresh install the preflight runs before .env has a BASE_URL, so nothing probes the
        // site. The closing checks run once it is written, and a web server not serving public/
        // is said under the setup link — where the operator is about to open it.
        $dir = sys_get_temp_dir() . '/provision_' . uniqid('', true);
        mkdir($dir . '/storage', 0755, true);
        file_put_contents($dir . '/.env', "APP_ENV=production\nBASE_URL=https://scent.example\n");
        $doctor = new Doctor($dir, '8.3.0', ['pdo_pgsql'], static fn (string $url): ?int => 403);

        $warnings = ProvisionCommand::closingWarnings($doctor);

        self::assertNotSame([], $warnings);
        self::assertStringContainsString($dir . '/public', implode("\n", $warnings));
    }

    public function testAHealthySiteClosesWithoutWarnings(): void
    {
        $checks = [Check::ok('document-root', 'ok'), Check::ok('asset-routing', 'ok')];

        self::assertSame([], ProvisionCommand::warningsIn($checks));
        self::assertSame(['web: broken'], ProvisionCommand::warningsIn([Check::warn('web', 'broken')]));
    }
}
