<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Fonts\Brotli\BrotliDecoders;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Thallo\Core\Content\Fonts\Woff2FaceReader;
use Thallo\Core\Http\Controllers\MediaAdminController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\FixtureFontBlobFiles;
use Thallo\Core\Tests\Support\Fonts\SqlLockHolder;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Media deletion and the font library take the same blob row lock first (plan Task 8): whichever
 * holds it, the other waits and then sees what was committed — a deleted file is never given to a
 * family, and a file a family was just given is never deleted.
 */
final class MediaFontGuardRaceTest extends AppTestCase
{
    private const FONT = __DIR__ . '/../../../fixtures/fonts/static-700.woff2';

    private function library(): FontLibrary
    {
        return new FontLibrary(
            $this->connection(),
            new FixtureFontBlobFiles(['fontfacea001' => self::FONT, 'fontfaceb001' => self::FONT]),
            new Woff2FaceReader(BrotliDecoders::best()),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(MediaUrlBatchResolver::class),
        );
    }

    private function blob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid, 'name' => $uuid . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $uuid . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testAFaceWaitsForADeletionInProgressAndThenRefuses(): void
    {
        $this->blob('fontfacea001');
        $this->blob('fontfaceb001');
        $id = $this->library()->create('Pair', 'sans-serif', ['fontfacea001']);

        // A deletion holds B's lock, its status already 'deleted', not yet committed.
        $holder = SqlLockHolder::hold([
            ['SELECT uuid FROM blobs WHERE uuid = ? FOR UPDATE', ['fontfaceb001']],
            ["UPDATE blobs SET status = 'deleted', deleted_at = now() WHERE uuid = ?", ['fontfaceb001']],
        ], 1.0);
        $start = microtime(true);
        try {
            $this->library()->addFace($id, 'fontfaceb001');
            self::fail('a deleted file became a face');
        } catch (UnreadableFont $e) {
            self::assertSame('That file isn\'t in the media library', $e->reason);
        }
        self::assertGreaterThan(0.5, microtime(true) - $start, 'it waited for the deletion');
        $holder->finish();
    }

    public function testADeletionWaitsForAFaceInProgressAndThenRefuses(): void
    {
        $this->blob('fontfacea001');
        $this->blob('fontfaceb001');
        $id = $this->library()->create('Pair', 'sans-serif', ['fontfacea001']);

        // A family is being given B: its lock held, the face inserted, not yet committed.
        $holder = SqlLockHolder::hold([
            ['SELECT uuid FROM blobs WHERE uuid = ? FOR UPDATE', ['fontfaceb001']],
            ['INSERT INTO font_faces (id, family_id, blob_uuid, weight_min, weight_max, italic, variable, unknown, '
                . "created_at) VALUES ('faceracing01', ?, 'fontfaceb001', 700, 700, false, false, false, now())",
                [$id]],
        ], 1.0);
        $start = microtime(true);
        $media = $this->container()->get(MediaAdminController::class);
        $res = $media->destroy(Request::create('/x', 'DELETE'), 'fontfaceb001');
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        self::assertGreaterThan(0.5, microtime(true) - $start, 'it waited for the face');
        $holder->finish();
        $blob = $this->connection()->table('blobs')->where('uuid', '=', 'fontfaceb001')->first();
        self::assertSame('active', $blob['status'] ?? null);
    }
}
