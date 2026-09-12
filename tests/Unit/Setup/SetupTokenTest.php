<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use Thallo\Core\Setup\Console\ProvisionCommand;
use Glueful\Installer\EnvWriter;
use PHPUnit\Framework\TestCase;

/**
 * SETUP_TOKEN gates the unauthenticated first-run POST /admin/setup (X-Setup-Token header).
 * Provision mints it exactly like APP_KEY/JWT_KEY/TOKEN_SALT: generated when empty, never
 * overwritten when already set.
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
}
