<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Tenancy\System\SystemFlags;

/**
 * A family's files are served to every visitor, so a file added to the library is made public: a
 * private one has no address, and its face would be left out of the fonts stylesheet — the family
 * would render in its fallback. An install's default upload visibility may be private.
 */
final class FontLibraryPublicFilesTest extends AppTestCase
{
    private const FONT = __DIR__ . '/../../../fixtures/fonts/static-700.woff2';

    private function library(): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            new FixtureFontBlobFiles(['fontprivate1' => self::FONT, 'fontprivate2' => self::FONT]),
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    private function privateBlob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => $uuid . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'private',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function visibility(string $uuid): ?string
    {
        $row = $this->connection()->table('blobs')->where('uuid', '=', $uuid)->first();
        return is_array($row) ? (string) $row['visibility'] : null;
    }

    public function testAFamilyMadeFromPrivateFilesServesThem(): void
    {
        $this->privateBlob('fontprivate1');
        $id = $this->library()->create('Private', 'serif', ['fontprivate1']);
        self::assertSame('public', $this->visibility('fontprivate1'));
        $face = $this->library()->snapshot()->family($id)?->faces[0] ?? null;
        self::assertNotSame('', $face['url'] ?? '', 'the face has an address');
    }

    public function testAPrivateFileAddedToAFamilyIsServedToo(): void
    {
        $this->privateBlob('fontprivate1');
        $this->privateBlob('fontprivate2');
        $id = $this->library()->create('Private', 'serif', ['fontprivate1']);
        $this->library()->addFace($id, 'fontprivate2');
        self::assertSame('public', $this->visibility('fontprivate2'));
    }

    /** What provision repairs: the files of families added before this fix. */
    public function testExistingPrivateLibraryFilesAreMadePublic(): void
    {
        $this->privateBlob('fontprivate1');
        $id = $this->library()->create('Private', 'serif', ['fontprivate1']);
        $this->connection()->table('blobs')->where('uuid', '=', 'fontprivate1')->update(['visibility' => 'private']);
        self::assertSame(1, $this->library()->publishFaceFiles());
        self::assertSame('public', $this->visibility('fontprivate1'));
        self::assertSame(0, $this->library()->publishFaceFiles(), 'nothing left to do');
        self::assertNotNull($this->library()->snapshot()->family($id));
    }

    public function testProvisionsFontStepRepairsThem(): void
    {
        $this->privateBlob('fontprivate1');
        $this->library()->create('Private', 'serif', ['fontprivate1']);
        $this->connection()->table('blobs')->where('uuid', '=', 'fontprivate1')->update(['visibility' => 'private']);
        $result = $this->container()->get(\Thallo\Core\Content\Fonts\FontLibraryUpgradeStep::class)->run();
        self::assertSame(1, $result['published']);
        self::assertSame('public', $this->visibility('fontprivate1'));
    }
}
