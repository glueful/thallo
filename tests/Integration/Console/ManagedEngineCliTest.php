<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;

/**
 * The framework's own `extensions:enable` / `extensions:disable` read the same management policy
 * as the admin: a package Thallo requires, or an engine a feature manages, is refused with the
 * place to go instead. Each runs as a real `php glueful` process; `--dry-run` keeps a regression
 * from writing config/extensions.php.
 */
final class ManagedEngineCliTest extends TestCase
{
    /** @return array{0: int, 1: string} exit code, stdout and stderr */
    private function glueful(string ...$args): array
    {
        $env = getenv();
        $env['APP_ENV'] = 'testing';
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/glueful', ...$args, '--dry-run', '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $env,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    public function testDisablingARequiredPackageIsRefused(): void
    {
        [$code, $out] = $this->glueful('extensions:disable', 'glueful/aegis');
        self::assertNotSame(0, $code, $out);
        self::assertStringContainsString('Required by Thallo.', $out);
    }

    public function testEnablingAManagedEngineIsRefusedAndNamesTheFeatureCommand(): void
    {
        [$code, $out] = $this->glueful('extensions:enable', 'glueful/commerce');
        self::assertNotSame(0, $code, $out);
        self::assertStringContainsString('thallo:capabilities:enable thallo.commerce', $out);
    }
}
