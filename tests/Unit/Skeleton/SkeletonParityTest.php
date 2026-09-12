<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Skeleton;

use PHPUnit\Framework\TestCase;

/**
 * skeleton/ is what create-project installs. Every file it shares with the dev root must be
 * byte-identical (the dev root IS a skeleton install plus tooling), and it must never contain
 * product code — that arrives in vendor/ as glueful/thallo-core.
 */
final class SkeletonParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testSharedFilesAreIdentical(): void
    {
        $shared = ['bootstrap/app.php', 'public/index.php', 'public/.htaccess', 'glueful', '.env.example'];
        foreach ($shared as $rel) {
            self::assertFileEquals("$this->root/$rel", "$this->root/skeleton/$rel", $rel);
        }
        foreach (glob("$this->root/config/*.php") as $file) {
            $rel = 'config/' . basename($file);
            self::assertFileEquals($file, "$this->root/skeleton/$rel", $rel);
        }
    }

    public function testTheSkeletonCarriesNoProductCodeAndRequiresCore(): void
    {
        foreach (['core', 'packages', 'tests', 'admin', 'scripts', 'docs/internal'] as $dir) {
            self::assertDirectoryDoesNotExist("$this->root/skeleton/$dir", "$dir never ships in the skeleton");
        }
        self::assertSame([], glob("$this->root/skeleton/app/*.php"));
        self::assertSame([], glob("$this->root/skeleton/routes/*.php"));
        self::assertSame([], glob("$this->root/skeleton/database/migrations/*.php"));

        $composer = json_decode((string) file_get_contents("$this->root/skeleton/composer.json"), true);
        self::assertSame('glueful/thallo', $composer['name']);
        self::assertSame('project', $composer['type']);
        self::assertMatchesRegularExpression('/^\^1\.0\.0-beta\.\d+$/', $composer['require']['glueful/thallo-core']);
        self::assertArrayNotHasKey('repositories', $composer, 'Packagist only — no path repositories in the skeleton');
        // Concatenated so the namespace-move lint (no product code in the operator's namespace)
        // does not read this assertion as a reference.
        $operators = 'App' . '\\';
        self::assertSame([$operators => 'app/'], $composer['autoload']['psr-4'], 'the operator\'s namespace');
        self::assertArrayHasKey('post-create-project-cmd', $composer['scripts']);
    }

    public function testTheSkeletonGitignoreProtectsWhatIsGenerated(): void
    {
        $ignore = (string) file_get_contents("$this->root/skeleton/.gitignore");
        $lines = ['/vendor/', '/.env', '/public/admin/', '/storage/cache/*', '/storage/logs/*', 'bootstrap/cache/'];
        foreach ($lines as $line) {
            self::assertStringContainsString($line, $ignore);
        }
    }

    public function testTheOperatorDirectoriesShipEmptyButPresent(): void
    {
        $dirs = ['app', 'routes', 'database/migrations', 'themes', 'storage/cache', 'storage/logs'];
        $dirs[] = 'storage/uploads';
        $dirs[] = 'public/storage';
        foreach ($dirs as $dir) {
            self::assertFileExists("$this->root/skeleton/$dir/.gitkeep", $dir);
        }
    }
}
