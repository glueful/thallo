<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\ThemeConfigError;
use Thallo\Render\ThemeLocator;

final class ThemeLadderTest extends AppTestCase
{
    private string $tmpThemes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpThemes = sys_get_temp_dir() . '/thallo-render-themes-' . bin2hex(random_bytes(4));
        mkdir($this->tmpThemes, 0755, true);
    }

    protected function tearDown(): void
    {
        // Best-effort temp cleanup (files then dirs).
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpThemes, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmpThemes);
        parent::tearDown();
    }

    public function testMissingAppThemeFallsBackToPackDefault(): void
    {
        $locator = new ThemeLocator('nonexistent', $this->tmpThemes);
        $paths = $locator->activePaths();

        self::assertSame('default', $paths['name']);
        self::assertCount(1, $paths['templates']); // pack default only
        self::assertStringContainsString('thallo-render/themes/default/templates', $paths['templates'][0]);
        self::assertFileExists($paths['templates'][0] . '/layout.twig');
    }

    public function testAppThemeIsFirstLoaderPathWithDefaultFallback(): void
    {
        mkdir($this->tmpThemes . '/mytheme/templates', 0755, true);
        file_put_contents(
            $this->tmpThemes . '/mytheme/theme.json',
            json_encode(['name' => 'mytheme', 'version' => '1.0.0', 'menus' => ['main']]),
        );

        $paths = (new ThemeLocator('mytheme', $this->tmpThemes))->activePaths();
        self::assertSame('mytheme', $paths['name']);
        self::assertCount(2, $paths['templates']);
        self::assertStringContainsString('/mytheme/templates', $paths['templates'][0]);
        self::assertStringContainsString('themes/default/templates', $paths['templates'][1]);
        self::assertStringContainsString('/mytheme/assets', $paths['assets']);
    }

    public function testInvalidAppThemeJsonIsALoudConfigError(): void
    {
        mkdir($this->tmpThemes . '/broken/templates', 0755, true);
        file_put_contents($this->tmpThemes . '/broken/theme.json', '{not json');

        $this->expectException(ThemeConfigError::class);
        new ThemeLocator('broken', $this->tmpThemes);
    }

    public function testBrokenPackDefaultIsAHardFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broken install');
        new ThemeLocator('default', $this->tmpThemes, $this->tmpThemes . '/no-pack-themes-here');
    }
}
