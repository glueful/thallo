<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Helpers\Utils;
use Glueful\Queue\QueueManager;
use Glueful\Storage\StorageManager;
use Glueful\Uploader\Jobs\GenerateBlobVariantsJob;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\MediaAdminController;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Optimize rewrites an image in place, which gives it a new version: its stored resized copies no
 * longer match, so the copies the site's pages ask for are queued again rather than left for the
 * next visitor to wait on.
 */
final class MediaOptimizeVariantsTest extends AppTestCase
{
    private ?object $originalQueue = null;
    /** @var QueueManager&object{pushed: list<array{job: string, data: array<string, mixed>, queue: string|null}>} */
    private QueueManager $queue;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\Glueful\Extensions\Media\ImageProcessor::class)) {
            self::markTestSkipped('glueful/media is not installed.');
        }
        $this->queue = new class extends QueueManager {
            /** @var list<array{job: string, data: array<string, mixed>, queue: string|null}> */
            public array $pushed = [];

            public function __construct()
            {
            }

            public function push(
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null,
            ): string {
                $this->pushed[] = ['job' => $job, 'data' => $data, 'queue' => $queue];

                return 'job-' . count($this->pushed);
            }
        };
        $this->originalQueue = $this->swapQueue($this->queue);
    }

    protected function tearDown(): void
    {
        if (isset($this->queue)) {
            $this->swapQueue($this->originalQueue);
        }
        parent::tearDown();
    }

    public function testThePagesCopiesArePreGeneratedForUploadedImages(): void
    {
        $pregenerate = (array) config($this->appContext(), 'uploads.image_processing.pregenerate');

        self::assertTrue((bool) ($pregenerate['enabled'] ?? false));
        // Every width the theme's srcsets and the media library's thumbnails ask for.
        self::assertSame(
            [160, 320, 480, 640, 768, 960, 1024, 1280, 1536, 1920],
            $pregenerate['widths'] ?? null,
        );
        self::assertSame([null, 'webp'], $pregenerate['formats'] ?? null);
    }

    public function testAnOptimizedImageQueuesItsCopiesAgain(): void
    {
        $uuid = $this->seedUncompressedPng();

        $response = $this->container()->get(MediaAdminController::class)
            ->optimize(Request::create('/v1/admin/media/' . $uuid . '/optimize', 'POST'), $uuid);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue(json_decode((string) $response->getContent(), true)['data']['changed']);
        self::assertSame(
            [['job' => GenerateBlobVariantsJob::class, 'data' => ['blob_uuid' => $uuid]]],
            array_map(static fn (array $p): array => ['job' => $p['job'], 'data' => $p['data']], $this->queue->pushed),
        );
    }

    public function testAnImageAlreadyOptimalQueuesNothing(): void
    {
        $uuid = $this->seedUncompressedPng();
        $controller = $this->container()->get(MediaAdminController::class);
        $optimize = fn () => $controller->optimize(
            Request::create('/v1/admin/media/' . $uuid . '/optimize', 'POST'),
            $uuid,
        );
        $optimize();
        $this->queue->pushed = [];

        $again = $optimize();

        self::assertFalse(json_decode((string) $again->getContent(), true)['data']['changed']);
        self::assertSame([], $this->queue->pushed, 'unchanged bytes keep their copies');
    }

    /** A PNG stored without compression, so optimizing it always makes it smaller. */
    private function seedUncompressedPng(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, 30, 90, 160));
        ob_start();
        imagepng($image, null, 0);
        $bytes = (string) ob_get_clean();

        $uuid = Utils::generateNanoID();
        $path = 'tests/optimize-' . $uuid . '.png';
        app($this->appContext(), StorageManager::class)->disk('uploads')->write($path, $bytes);
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pic.png',
            'mime_type' => 'image/png',
            'size' => strlen($bytes),
            'url' => $path,
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /**
     * Put a queue in place of the shared app's resolved one, returning what was there. The app is
     * shared across test classes, so its QueueManager may already be resolved and cached: a new
     * definition alone would not reach it, and Container::reset() would drop every service.
     */
    private function swapQueue(?object $queue): ?object
    {
        $container = $this->appContext()->getContainer();

        return \Closure::bind(static function (object $c) use ($queue): ?object {
            $previous = $c->singletons[QueueManager::class] ?? null;
            if ($queue === null) {
                unset($c->singletons[QueueManager::class]);
            } else {
                $c->singletons[QueueManager::class] = $queue;
            }

            return $previous;
        }, null, \Glueful\Container\Container::class)($container);
    }
}
