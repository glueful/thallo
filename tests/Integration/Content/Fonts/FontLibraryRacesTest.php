<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\FontLibraryRefusal;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Core\Tests\Support\Fonts\SqlLockHolder;
use Thallo\Tenancy\System\SystemFlags;

/**
 * One lock order — blob rows (all of them, sorted) before the family row, faces last — with a second
 * connection interleaved (plan Task 2).
 */
final class FontLibraryRacesTest extends AppTestCase
{
    private const FONTS = __DIR__ . '/../../../fixtures/fonts';

    private function library(FixtureFontBlobFiles $files): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            $files,
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    private function blob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid . '.woff2',
            'mime_type' => 'font/woff2',
            'size' => 1000,
            'url' => '/uploads/' . $uuid . '.woff2',
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, string> */
    private function fixtures(string ...$uuids): array
    {
        $paths = [self::FONTS . '/static-700.woff2', self::FONTS . '/static-400-italic.woff2'];
        $map = [];
        foreach ($uuids as $i => $uuid) {
            $this->blob($uuid);
            $map[$uuid] = $paths[$i % 2];
        }
        return $map;
    }

    public function testTwoConcurrentRemovalsCannotLeaveAFamilyWithoutFaces(): void
    {
        $library = $this->library(new FixtureFontBlobFiles($this->fixtures('fontfacea001', 'fontfaceb001')));
        $id = $library->create('Pair', 'sans-serif', ['fontfacea001', 'fontfaceb001']);

        // Connection 2 removes face A under the family row lock, and commits a second later.
        $holder = SqlLockHolder::hold([
            ['UPDATE font_families SET updated_at = updated_at WHERE id = ?', [$id]],
            ['DELETE FROM font_faces WHERE family_id = ? AND blob_uuid = ?', [$id, 'fontfacea001']],
        ], 1.0);
        $start = microtime(true);
        try {
            $library->removeFace($id, 'fontfaceb001');
            self::fail('removed the last face');
        } catch (FontLibraryRefusal $e) {
            self::assertSame('A family keeps at least one face', $e->getMessage());
        }
        self::assertGreaterThan(0.5, microtime(true) - $start, 'the removal waited for the family lock');
        $holder->finish();

        $faces = $library->snapshot()->family($id)?->faces ?? [];
        self::assertSame(['fontfaceb001'], array_column($faces, 'blob_uuid'));
    }

    public function testABlobDeletedWhileReadingIsRefused(): void
    {
        $map = $this->fixtures('fontfacea001', 'fontfaceb001');
        $library = $this->library(new FixtureFontBlobFiles($map));
        $id = $library->create('One', 'sans-serif', ['fontfacea001']);

        // The read completes; the blob is deleted before the transaction starts.
        $deleting = $this->library(new FixtureFontBlobFiles($map, function (string $uuid): void {
            $this->connection()->table('blobs')->where('uuid', '=', $uuid)->update(['status' => 'deleted']);
        }));
        try {
            $deleting->addFace($id, 'fontfaceb001');
            self::fail('added a deleted file');
        } catch (UnreadableFont $e) {
            self::assertSame('That file isn\'t in the media library', $e->reason);
        }
        self::assertSame(['fontfacea001'], array_column($library->snapshot()->family($id)?->faces ?? [], 'blob_uuid'));
    }

    /**
     * Operation 1 needs {B, A} (the upgrade's Text from B, Headings from A); operation 2 is a create of
     * {A, B}, which locks A first. While operation 1 waits on A, B must still be free — so operation 2
     * can take it and finish: neither holds what the other waits for, and nothing deadlocks.
     */
    public function testTwoMultiBlobOperationsNeverDeadlock(): void
    {
        $library = $this->library(new FixtureFontBlobFiles($this->fixtures('fontblobaaaa', 'fontblobbbbb')));
        $reader = new Woff2FaceReader(BrotliDecoders::best());
        $text = $reader->read(self::FONTS . '/static-700.woff2');
        $headings = $reader->read(self::FONTS . '/static-400-italic.woff2');

        $holder = SqlLockHolder::hold(
            [['SELECT uuid FROM blobs WHERE uuid = ? FOR UPDATE', ['fontblobaaaa']]],
            1.0,
            [['SELECT uuid FROM blobs WHERE uuid = ? FOR UPDATE NOWAIT', ['fontblobbbbb']]],
        );
        $start = microtime(true);
        [$t, $h] = $library->withBlobsLocked(['fontblobbbbb', 'fontblobaaaa'], fn (): array => [
            $library->createWithFaces('Site text', 'system-ui', ['fontblobbbbb' => $text]),
            $library->createWithFaces('Site headings', 'system-ui', ['fontblobaaaa' => $headings]),
        ]);
        self::assertGreaterThan(0.5, microtime(true) - $start, 'operation 1 waited for A');
        self::assertSame(['ok'], $holder->finish(), 'B was free while operation 1 waited for A');

        $snapshot = $library->snapshot();
        self::assertSame('Site text', $snapshot->family($t)?->name);
        self::assertSame('Site headings', $snapshot->family($h)?->name);
    }

    public function testCreateWithFacesOutsideTheLockIsRefused(): void
    {
        $library = $this->library(new FixtureFontBlobFiles($this->fixtures('fontblobaaaa')));
        $face = (new Woff2FaceReader(BrotliDecoders::best()))->read(self::FONTS . '/static-700.woff2');
        $this->expectException(\LogicException::class);
        $library->createWithFaces('Unlocked', 'sans-serif', ['fontblobaaaa' => $face]);
    }
}
