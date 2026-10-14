<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updates\RunnerLauncher;

/**
 * Simple mode: PHP-FPM starts the update runner itself, in the background, and does not wait for it —
 * the request answers while the runner works on.
 */
final class RunnerLauncherTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-launch-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/bin', 0777, true);
        mkdir($this->base . '/storage/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testLaunchStartsTheRunnerInTheBackgroundAsASimpleModeTick(): void
    {
        // A stand-in runner: records how it was started, then outlives the launch by two seconds.
        file_put_contents($this->base . '/bin/thallo-updater', '<?php file_put_contents(__DIR__ . "/../seen.json", '
            . 'json_encode(["argv" => $argv, "simple" => getenv("THALLO_UPDATE_SIMPLE"), '
            . '"composer" => getenv("COMPOSER_BINARY"), "cwd" => getcwd()])); sleep(2);');
        $launcher = new RunnerLauncher($this->base, PHP_BINARY, '/usr/local/bin/composer');

        $started = microtime(true);
        self::assertTrue($launcher->launch());
        self::assertLessThan(1.5, microtime(true) - $started, 'the launch does not wait for the runner');

        $seen = null;
        for ($i = 0; $i < 50 && $seen === null; $i++) {
            usleep(100_000);
            $seen = json_decode((string) @file_get_contents($this->base . '/seen.json'), true);
        }
        self::assertIsArray($seen, 'the runner ran');
        self::assertSame('tick', $seen['argv'][1]);
        self::assertSame('1', $seen['simple']);
        self::assertSame('/usr/local/bin/composer', $seen['composer']);
        self::assertSame(realpath($this->base), realpath((string) $seen['cwd']));
    }

    public function testWithoutAnInstalledRunnerThereIsNothingToLaunch(): void
    {
        $launcher = new RunnerLauncher($this->base, PHP_BINARY, null);
        self::assertFalse($launcher->available());
        self::assertFalse($launcher->launch());
    }
}
