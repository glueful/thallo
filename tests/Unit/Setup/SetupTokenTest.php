<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use Thallo\Core\Setup\Console\ProvisionCommand;
use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

/**
 * SETUP_TOKEN gates the unauthenticated first-run POST /admin/setup (X-Setup-Token header).
 * Provision mints it exactly like APP_KEY/JWT_KEY/TOKEN_SALT: generated when empty, never
 * overwritten when already set. Once setup has run it has no use, so provision blanks it instead:
 * provision runs as the site's owner, who can write .env when PHP-FPM cannot.
 */
final class SetupTokenTest extends TestCase
{
    private string $env;

    protected function setUp(): void
    {
        $this->env = (string) tempnam(sys_get_temp_dir(), 'thallo-env');
    }

    protected function tearDown(): void
    {
        @unlink($this->env);
    }

    public function testProvisionMintsASetupTokenWhenNoneIsSet(): void
    {
        file_put_contents($this->env, "APP_KEY=abc\nSETUP_TOKEN=\n");

        $token = ProvisionCommand::ensureSetupToken(new EnvWriter($this->env));

        self::assertGreaterThanOrEqual(32, strlen($token));
        self::assertSame(
            $token,
            (new EnvWriter($this->env))->get('SETUP_TOKEN'),
            'written to .env like the other keys',
        );
    }

    public function testProvisionKeepsAnExistingSetupToken(): void
    {
        file_put_contents($this->env, "SETUP_TOKEN=already-chosen\n");

        self::assertSame('already-chosen', ProvisionCommand::ensureSetupToken(new EnvWriter($this->env)));
        self::assertSame('already-chosen', (new EnvWriter($this->env))->get('SETUP_TOKEN'));
    }

    public function testOnceSetupHasRunProvisionBlanksTheToken(): void
    {
        file_put_contents($this->env, "APP_KEY=abc\nSETUP_TOKEN=left-behind\n");

        self::assertNull(ProvisionCommand::ensureSetupToken(new EnvWriter($this->env), installed: true));
        self::assertSame('', (new EnvWriter($this->env))->get('SETUP_TOKEN'));
        self::assertSame('abc', (new EnvWriter($this->env))->get('APP_KEY'));
    }

    public function testOnceSetupHasRunProvisionDoesNotMintANewToken(): void
    {
        file_put_contents($this->env, "APP_KEY=abc\nSETUP_TOKEN=\n");
        $before = (string) file_get_contents($this->env);

        self::assertNull(ProvisionCommand::ensureSetupToken(new EnvWriter($this->env), installed: true));
        self::assertSame($before, file_get_contents($this->env), 'nothing to change, nothing written');
    }
}
