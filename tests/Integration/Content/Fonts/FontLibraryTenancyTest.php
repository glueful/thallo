<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Each workspace's library is its own, under real tenancy enforcement: a family is made only from the
 * workspace's own media, and one workspace never sees another's families (plan Task 2).
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class FontLibraryTenancyTest extends RetrofittedTenantTestCase
{
    private const FONT = __DIR__ . '/../../../fixtures/fonts/static-700.woff2';

    private function library(): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            new FixtureFontBlobFiles(['fontblobownb' => self::FONT]),
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    private function inA(callable $fn): mixed
    {
        return $this->runAsTenant(self::$tenantAUuid, $fn);
    }

    private function inB(callable $fn): mixed
    {
        return $this->runAsTenant(self::$tenantBUuid, $fn);
    }

    /** A font upload in workspace B: the blob, and B's ownership row, as an upload records it. */
    private function uploadInB(): void
    {
        $this->runAsSystem(fn () => $this->connection()->table('blobs')->insert([
            'uuid' => 'fontblobownb',
            'name' => 'b.woff2',
            'mime_type' => 'font/woff2',
            'size' => 1000,
            'url' => '/uploads/fontblobownb.woff2',
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]));
        $this->runAsTenant(self::$tenantBUuid, fn () => $this->connection()->table('media_assets')->insert([
            'blob_uuid' => 'fontblobownb',
            'created_at' => date('Y-m-d H:i:s'),
        ]));
    }

    public function testAnotherWorkspacesBlobIsRefused(): void
    {
        $this->uploadInB();

        try {
            $this->inA(fn () => $this->library()->create('Stolen', 'serif', ['fontblobownb']));
            self::fail('workspace A made a family from B\'s file');
        } catch (UnreadableFont $e) {
            self::assertSame('That file isn\'t in the media library', $e->reason);
        }

        $id = $this->inB(fn () => $this->library()->create('Own', 'serif', ['fontblobownb']));
        self::assertSame('Own', $this->inB(fn () => $this->library()->snapshot()->family($id)?->name));
        self::assertNull($this->inA(fn () => $this->library()->snapshot()->family($id)));
        self::assertSame(0, $this->inA(fn () => $this->library()->snapshot()->generation()));
        self::assertSame(1, $this->inB(fn () => $this->library()->snapshot()->generation()));
    }
}
