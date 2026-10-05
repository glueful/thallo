<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\FontLibraryRefusal;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Tenancy\System\SystemFlags;

/**
 * One add reads a bounded amount (final review): at most 18 files, and no new file once reading has
 * taken 15 seconds in all — so a request ends with a reason, not PHP's time limit.
 */
final class FontLibraryCreateLimitsTest extends AppTestCase
{
    private const FONT = __DIR__ . '/../../../fixtures/fonts/static-700.woff2';

    /** @param list<string> $blobs */
    private function library(array $blobs, ?\Closure $clock = null): FontLibrary
    {
        $now = date('Y-m-d H:i:s');
        foreach ($blobs as $uuid) {
            $this->connection()->table('blobs')->insert([
                'uuid' => $uuid, 'name' => $uuid . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
                'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
                'status' => 'active', 'created_by' => 'user00000001', 'created_at' => $now,
            ]);
        }
        return new FontLibrary(
            $this->connection(),
            new FixtureFontBlobFiles(array_fill_keys($blobs, self::FONT)),
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
            $clock,
        );
    }

    public function testNineteenFilesAreRefusedBeforeAnyIsRead(): void
    {
        $blobs = array_map(static fn (int $i): string => sprintf('fontlimit%03d', $i), range(1, 19));
        try {
            $this->library($blobs)->create('Many', 'serif', $blobs);
            self::fail('19 files were accepted');
        } catch (FontLibraryRefusal $e) {
            self::assertSame('Add at most 18 files at a time', $e->getMessage());
            self::assertSame('invalid', $e->kind);
        }
        self::assertSame([], $this->connection()->table('font_families')->get());
    }

    public function testReadingStopsOnceTheWholeAddHasTakenFifteenSeconds(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $now = $t;
            $t += 8.0; // every file takes eight seconds
            return $now;
        };
        $blobs = ['fontslow0001', 'fontslow0002', 'fontslow0003'];
        try {
            $this->library($blobs, $clock)->create('Slow', 'serif', $blobs);
            self::fail('the add read on past its budget');
        } catch (FontLibraryRefusal $e) {
            self::assertSame('These files took too long to read; add fewer at a time', $e->getMessage());
        }
        self::assertSame([], $this->connection()->table('font_families')->get());
    }
}
