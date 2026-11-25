<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Media\ImagePregeneration;

/**
 * Thallo's upload-time copies reach every install, not only new ones: a site's config/uploads.php is
 * its own file, written at install, so the block comes from core unless the site has one of its own.
 */
final class ImagePregenerationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/thallo-pregen-' . uniqid('', true);
        mkdir($this->dir . '/production', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/production/uploads.php', $this->dir . '/uploads.php'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir . '/production');
        rmdir($this->dir);
    }

    public function testASiteWithoutItsOwnBlockGetsThallos(): void
    {
        self::assertFalse(ImagePregeneration::siteSetsItsOwn($this->dir, 'production'), 'no uploads.php');

        $this->write('uploads.php', ['image_processing' => ['cache_enabled' => true]]);
        self::assertFalse(ImagePregeneration::siteSetsItsOwn($this->dir, 'production'));
    }

    public function testASiteBlockOrAnEnvironmentOverlayBlockWins(): void
    {
        $this->write('uploads.php', ['image_processing' => ['pregenerate' => ['widths' => [400]]]]);
        self::assertTrue(ImagePregeneration::siteSetsItsOwn($this->dir, 'production'));

        unlink($this->dir . '/uploads.php');
        $this->write('production/uploads.php', ['image_processing' => ['pregenerate' => ['enabled' => false]]]);
        self::assertTrue(ImagePregeneration::siteSetsItsOwn($this->dir, 'production'));
        self::assertFalse(ImagePregeneration::siteSetsItsOwn($this->dir, 'staging'), 'another environment');
    }

    public function testThallosBlockAsksForEveryWidthTheThemeAndLibraryUse(): void
    {
        $block = ImagePregeneration::defaults();

        self::assertSame([160, 320, 480, 640, 768, 960, 1024, 1280, 1536, 1920], $block['widths']);
        self::assertSame([null, 'webp'], $block['formats']);
        self::assertSame(['image/jpeg', 'image/png', 'image/webp'], $block['mime_types']);
        self::assertSame('default', $block['queue']);
    }

    /** @param array<string, mixed> $config */
    private function write(string $file, array $config): void
    {
        file_put_contents($this->dir . '/' . $file, '<?php return ' . var_export($config, true) . ';');
    }
}
