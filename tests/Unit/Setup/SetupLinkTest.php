<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use Thallo\Core\Http\Controllers\SetupController;
use Thallo\Core\Setup\Console\ProvisionCommand;
use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

/**
 * The browser first-run is a LINK: provision prints /admin/setup?st=<SETUP_TOKEN>, the page hands
 * the token back as X-Setup-Token, and a completed setup blanks the token so the link dies with
 * the endpoint's own 409 lock. `st` is deliberately the only place the parameter name lives.
 */
final class SetupLinkTest extends TestCase
{
    public function testTheBrowserLinkCarriesTheTokenUnderTheShortParameter(): void
    {
        $lines = ProvisionCommand::nextSteps('https://thallo.dev/', 'tok123');

        self::assertStringContainsString('https://thallo.dev/admin/setup?st=tok123', $lines[0]);
        self::assertSame(SetupController::TOKEN_QUERY_PARAM, 'st');
    }

    public function testWithoutATokenTheLinkIsPlain(): void
    {
        $lines = ProvisionCommand::nextSteps(null, null);
        self::assertStringContainsString('http://localhost:8000/admin/setup', $lines[0]);
        self::assertStringNotContainsString('?st=', $lines[0]);
    }

    public function testACompletedSetupBlanksTheTokenAndLeavesTheRestOfEnvAlone(): void
    {
        $env = (string) tempnam(sys_get_temp_dir(), 'thallo-env');
        file_put_contents($env, "APP_KEY=abc\nSETUP_TOKEN=tok123\n");

        SetupController::clearSetupToken($env);

        self::assertSame('', (new EnvWriter($env))->get('SETUP_TOKEN'));
        self::assertSame('abc', (new EnvWriter($env))->get('APP_KEY'));
        @unlink($env);
    }
}
