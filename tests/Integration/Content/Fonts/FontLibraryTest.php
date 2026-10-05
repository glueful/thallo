<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontId;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\FontLibraryRefusal;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Tenancy\System\SystemFlags;

/** The workspace font library (block typeface spec §2.3–§2.6; plan Task 2). */
final class FontLibraryTest extends AppTestCase
{
    private const FONTS = __DIR__ . '/../../../fixtures/fonts';

    private FixtureFontBlobFiles $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new FixtureFontBlobFiles([]);
    }

    private function library(): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            $this->files,
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    /** A media library row for a fixture file, as an upload makes it. */
    private function blob(string $uuid, string $fixture, string $mime = 'font/woff2', string $status = 'active'): string
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $fixture,
            'mime_type' => $mime,
            'size' => (int) filesize(self::FONTS . '/' . $fixture),
            'url' => '/uploads/' . $uuid . '.woff2',
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => $status,
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->files->map($uuid, self::FONTS . '/' . $fixture);
        return $uuid;
    }

    private function rows(string $table): int
    {
        return count($this->connection()->table($table)->get());
    }

    public function testCreateReadsFacesFromTheFiles(): void
    {
        $roman = $this->blob('fontroman001', 'variable.woff2');
        $italic = $this->blob('fontitalic01', 'variable-italic.woff2');
        $library = $this->library();

        $id = $library->create('Figtree', 'serif', [$roman, $italic]);

        self::assertTrue(FontId::isValid($id));
        self::assertFalse(FontId::isReserved($id));
        $family = $library->snapshot()->family($id);
        self::assertNotNull($family);
        self::assertSame(['Figtree', 'serif', false], [$family->name, $family->fallback, $family->removed]);
        $faces = array_map(static fn (array $f): array => [
            $f['blob_uuid'], $f['weight_min'], $f['weight_max'], $f['italic'], $f['variable'], $f['unknown'],
        ], $family->faces);
        // Upright before italic, then by weight.
        self::assertSame([
            [$roman, 300, 900, false, true, false],
            [$italic, 300, 900, true, true, false],
        ], $faces);
        self::assertSame('uploaded', $library->snapshot()->resolution($id));
        self::assertSame([$id], array_map(static fn ($f) => $f->id, $library->snapshot()->active()));
    }

    public function testAnUnreadableFileRefusesTheWholeCreate(): void
    {
        $good = $this->blob('fontstatic01', 'static-700.woff2');
        $bad = $this->blob('notafont0001', 'not-a-font.woff2');

        try {
            $this->library()->create('Broken', 'sans-serif', [$good, $bad]);
            self::fail('created');
        } catch (UnreadableFont $e) {
            self::assertSame('Not a WOFF2 font', $e->reason);
        }
        self::assertSame([0, 0], [$this->rows('font_families'), $this->rows('font_faces')]);
        self::assertSame(0, $this->library()->snapshot()->generation());
    }

    public function testNameAndFallbackAreChecked(): void
    {
        $blob = $this->blob('fontstatic01', 'static-700.woff2');
        $cases = [['  ', 'serif', 'Name the family'], ['Ok', 'fantasy', 'Choose a fallback']];
        foreach ($cases as [$name, $fallback, $why]) {
            try {
                $this->library()->create($name, $fallback, [$blob]);
                self::fail('created');
            } catch (FontLibraryRefusal $e) {
                self::assertSame($why, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('Add at least one file');
        $this->library()->create('Empty', 'serif', []);
    }

    public function testRenameKeepsTheId(): void
    {
        $library = $this->library();
        $id = $library->create('Old', 'sans-serif', [$this->blob('fontstatic01', 'static-700.woff2')]);
        $library->rename($id, '  New name  ');
        $library->setFallback($id, 'monospace');
        $family = $library->snapshot()->family($id);
        self::assertSame(['New name', 'monospace'], [$family?->name, $family?->fallback]);
    }

    public function testTheLastFaceCannotBeRemoved(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $b = $this->blob('fontitalic01', 'static-400-italic.woff2');
        $id = $library->create('Pair', 'sans-serif', [$a, $b]);
        $library->removeFace($id, $a);

        $this->expectException(FontLibraryRefusal::class);
        $this->expectExceptionMessage('A family keeps at least one face');
        $library->removeFace($id, $b);
    }

    public function testTheSameFaceTwiceIsRefused(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $id = $library->create('One', 'sans-serif', [$a]);
        try {
            $library->addFace($id, $a);
            self::fail('added');
        } catch (FontLibraryRefusal $e) {
            self::assertSame('That file is already in this family', $e->getMessage());
        }
        $this->expectExceptionMessage('That file is already in this family');
        $library->create('Twice', 'sans-serif', [$a, $a]);
    }

    public function testRemoveRestorePurge(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $id = $library->create('Gone', 'sans-serif', [$a]);

        $library->remove($id);
        $snapshot = $library->snapshot();
        self::assertTrue($snapshot->family($id)?->removed);
        self::assertSame([], $snapshot->active());
        self::assertSame('missing', $snapshot->resolution($id));
        self::assertTrue($library->isLibraryBlob($a));

        $library->restore($id);
        self::assertSame('uploaded', $library->snapshot()->resolution($id));
        try {
            $library->purge($id);
            self::fail('purged a current family');
        } catch (FontLibraryRefusal $e) {
            self::assertSame('Remove this family before deleting it permanently', $e->getMessage());
        }

        $library->remove($id);
        $library->purge($id);
        self::assertNull($library->snapshot()->family($id));
        self::assertSame([0, 0], [$this->rows('font_families'), $this->rows('font_faces')]);
        self::assertFalse($library->isLibraryBlob($a));
    }

    public function testEveryMutationBumpsTheGeneration(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $b = $this->blob('fontitalic01', 'static-400-italic.woff2');
        $generations = [$library->snapshot()->generation()];
        $id = $library->create('Counted', 'sans-serif', [$a]);
        $steps = [
            fn () => $library->rename($id, 'Renamed'),
            fn () => $library->setFallback($id, 'serif'),
            fn () => $library->addFace($id, $b),
            fn () => $library->removeFace($id, $b),
            fn () => $library->readAgain($id),
            fn () => $library->remove($id),
            fn () => $library->restore($id),
            fn () => $library->remove($id),
            fn () => $library->purge($id),
        ];
        $generations[] = $library->snapshot()->generation();
        foreach ($steps as $step) {
            $step();
            $generations[] = $library->snapshot()->generation();
        }
        self::assertSame(range(0, count($steps) + 1), $generations);
    }

    public function testADeletedOrForeignTypedBlobIsRefused(): void
    {
        $deleted = $this->blob('fontdeleted1', 'static-700.woff2', status: 'deleted');
        $png = $this->blob('notawoff2png', 'static-700.woff2', mime: 'image/png');
        foreach ([$deleted, $png, 'nosuchblob01'] as $blob) {
            if ($blob === 'nosuchblob01') {
                $this->files->map($blob, self::FONTS . '/static-700.woff2');
            }
            try {
                $this->library()->create('Refused', 'sans-serif', [$blob]);
                self::fail('created from ' . $blob);
            } catch (UnreadableFont $e) {
                self::assertSame('That file isn\'t in the media library', $e->reason, $blob);
            }
        }
        self::assertSame(0, $this->rows('font_families'));
    }

    public function testIsLibraryBlobCoversRemovedFamilies(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $other = $this->blob('fontunused01', 'static-400-italic.woff2');
        $id = $library->create('Kept', 'sans-serif', [$a]);
        self::assertTrue($library->isLibraryBlob($a));
        self::assertFalse($library->isLibraryBlob($other));
        $library->remove($id);
        self::assertTrue($library->isLibraryBlob($a));
    }

    public function testTheContainerBindsTheLibraryAsTheRenderReader(): void
    {
        $reader = $this->container()->get(\Thallo\Contracts\Fonts\FontLibraryReader::class);
        self::assertSame($this->container()->get(FontLibrary::class), $reader);
        self::assertSame(0, $reader->snapshot()->generation());
    }

    public function testBuiltInsResolveAndUnknownIdsAreMissing(): void
    {
        $snapshot = $this->library()->snapshot();
        foreach (FontId::RESERVED as $id) {
            self::assertSame('builtin', $snapshot->resolution($id), $id);
        }
        self::assertSame('missing', $snapshot->resolution('Ab3dE5fG7hJ9'));
        self::assertSame('missing', $snapshot->resolution('not an id'));
    }

    public function testReadAgainUpdatesAnUnknownFaceFromItsFile(): void
    {
        $library = $this->library();
        $a = $this->blob('fontstatic01', 'static-700.woff2');
        $id = $library->create('Upgraded', 'sans-serif', [$a]);
        // An upgraded face whose file could not be read: kept with the compatibility declaration.
        $this->connection()->table('font_faces')->where('family_id', '=', $id)
            ->update(['weight_min' => 100, 'weight_max' => 900, 'unknown' => true]);

        $library->readAgain($id);

        $face = $library->snapshot()->family($id)?->faces[0];
        self::assertIsArray($face);
        self::assertSame([700, 700, false], [$face['weight_min'], $face['weight_max'], $face['unknown']]);
    }
}
