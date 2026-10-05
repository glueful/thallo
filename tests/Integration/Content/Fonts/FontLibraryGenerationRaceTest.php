<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Core\Tests\Support\Fonts\SqlLockHolder;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The first two changes in a workspace at once (final review minor): both find no generation row.
 * The second waits for the first and then counts on from it — it never fails on the row the first
 * inserted.
 */
final class FontLibraryGenerationRaceTest extends AppTestCase
{
    private const FONT = __DIR__ . '/../../../fixtures/fonts/static-700.woff2';

    private function library(): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            new FixtureFontBlobFiles(['fontraceblob' => self::FONT]),
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    public function testASecondFirstChangeCountsOnInsteadOfFailing(): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => 'fontraceblob', 'name' => 'race.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/race.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // Another first change: its generation row inserted, not yet committed.
        $holder = SqlLockHolder::hold([
            ["INSERT INTO settings (key, value, updated_at) VALUES ('thallo.fonts.generation', '1', now())", []],
        ], 1.0);
        $this->library()->create('Race', 'serif', ['fontraceblob']);
        $holder->finish();
        $row = $this->connection()->table('settings')->where('key', '=', 'thallo.fonts.generation')->first();
        self::assertSame('2', (string) ($row['value'] ?? ''));
    }
}
