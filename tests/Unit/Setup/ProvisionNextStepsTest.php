<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Setup\Console\ProvisionCommand;
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
}
