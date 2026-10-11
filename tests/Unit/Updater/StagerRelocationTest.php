<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\RealHost;
use Thallo\Core\Updater\Stager;

final class StagerRelocationTest extends TestCase
{
    private string $base;
    private string $composer;

    protected function setUp(): void
    {
        $this->composer = (string) (getenv('COMPOSER_BINARY') ?: trim((string) shell_exec('command -v composer')));
        if ($this->composer === '') {
            self::markTestSkipped('composer not found');
        }
        $this->base = sys_get_temp_dir() . '/thallo-stage-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/app', 0777, true);
        mkdir($this->base . '/legacy', 0777, true);
        $greeter = "<?php\nnamespace Site;\nfinal class Greeter { public function hi(): string { return 'hi'; } }\n";
        file_put_contents($this->base . '/app/Greeter.php', $greeter);
        file_put_contents($this->base . '/legacy/OldThing.php', "<?php\nfinal class OldThing {}\n");
        $helpers = "<?php\nfunction thallo_helper(): string { return 'helped'; }\n";
        file_put_contents($this->base . '/helpers.php', $helpers);
        file_put_contents($this->base . '/composer.json', json_encode([
            'name' => 'acme/site', 'type' => 'project',
            'autoload' => ['psr-4' => ['Site\\' => 'app/'], 'classmap' => ['legacy/'], 'files' => ['helpers.php']],
            'config' => ['optimize-autoloader' => true],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->composerIn($this->base, ['install', '--no-interaction', '--no-progress']);
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    public function testTheRelocatedVendorLoadsRootClassesAfterTheStageIsGone(): void
    {
        $stage = $this->base . '/storage/framework/updates/op/stage';
        $log = $this->base . '/stage.log';
        $stager = new Stager(new RealHost($log, []), PHP_BINARY, $this->composer, $this->base . '/composer-home');
        $stager->prepare($this->base, $stage);
        self::assertTrue(is_link($stage . '/app'), 'autoload roots are linked, not copied');
        // The autoloader is generated in the stage exactly as `composer update` would generate it.
        $this->composerIn($stage, ['dump-autoload', '--optimize', '--no-interaction']);

        rename($this->base . '/vendor', $this->base . '/vendor.old');
        rename($stage . '/vendor', $this->base . '/vendor');
        exec('rm -rf ' . escapeshellarg(dirname($stage)) . ' ' . escapeshellarg($this->base . '/vendor.old'));

        $classmap = (string) file_get_contents($this->base . '/vendor/composer/autoload_classmap.php');
        self::assertStringNotContainsString('/stage/', $classmap, 'no path points into the deleted stage');
        $probe = 'require "vendor/autoload.php"; echo (new Site\Greeter())->hi(),'
            . ' class_exists("OldThing") ? ":classmap" : ":missing", ":", thallo_helper();';
        // Run in the live site: "vendor/autoload.php" must be the relocated tree, not this repository's.
        $cmd = 'cd ' . escapeshellarg($this->base) . ' && '
            . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame('hi:classmap:helped', implode('', $out));
    }

    /** @param list<string> $args */
    private function composerIn(string $dir, array $args): void
    {
        $cmd = escapeshellarg($this->composer) . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' --working-dir=' . escapeshellarg($dir) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
