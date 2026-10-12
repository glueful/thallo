<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\ChildProcess;

final class ChildProcessTest extends TestCase
{
    public function testAQuietChildStillHeartbeats(): void
    {
        $beats = 0;
        $log = tempnam(sys_get_temp_dir(), 'cp');
        $beat = static function () use (&$beats): void {
            $beats++;
        };
        $code = ChildProcess::run(['sh', '-c', 'sleep 11'], sys_get_temp_dir(), [], [], $log, $beat, 60);
        self::assertSame(0, $code);
        self::assertGreaterThanOrEqual(2, $beats, 'a heartbeat every 5 s while the child prints nothing');
    }

    public function testOutputGoesToTheLogAndTheExitCodeComesBack(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cp');
        $argv = ['sh', '-c', 'echo hello; echo oops >&2; exit 3'];
        $code = ChildProcess::run($argv, sys_get_temp_dir(), [], [], $log, static function (): void {
        }, 60);
        self::assertSame(3, $code);
        self::assertStringContainsString("hello\noops", (string) file_get_contents($log));
    }

    public function testTheEnvironmentIsMergedNotReplaced(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cp');
        $argv = ['sh', '-c', 'echo "$THALLO_UPDATE_CHILD:$PATH"'];
        $env = ['THALLO_UPDATE_CHILD' => '1'];
        ChildProcess::run($argv, sys_get_temp_dir(), $env, [], $log, static function (): void {
        }, 60);
        self::assertMatchesRegularExpression('/^1:\S+/', (string) file_get_contents($log));
    }
}
