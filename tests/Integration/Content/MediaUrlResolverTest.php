<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineMediaUrlResolver;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;

final class MediaUrlResolverTest extends AppTestCase
{
    /** Insert a blobs row directly (the framework table; media uploads are out of scope). */
    private function seedBlob(string $visibility = 'public', string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/pic.jpg',
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function resolver(bool $enabled = true, mixed $access = 'upload_only'): EngineMediaUrlResolver
    {
        return new EngineMediaUrlResolver($this->connection(), '/api/v1/blobs', $enabled, $access);
    }

    public function testPublicRetrievableBlobResolvesToTheBlobRoute(): void
    {
        $uuid = $this->seedBlob();
        self::assertSame('/api/v1/blobs/' . $uuid, $this->resolver()->url($uuid));
    }

    public function testEveryDenyConditionReturnsNull(): void
    {
        $public = $this->seedBlob();

        // Route-parity matrix: public blob, gated access modes (incl. the default install).
        foreach (['private', true, 'true', 1] as $gated) {
            self::assertNull($this->resolver(access: $gated)->url($public), var_export($gated, true));
        }
        // Uploads disabled entirely.
        self::assertNull($this->resolver(enabled: false)->url($public));
        // Private blob.
        self::assertNull($this->resolver()->url($this->seedBlob(visibility: 'private')));
        // Deleted blob.
        self::assertNull($this->resolver()->url($this->seedBlob(status: 'deleted')));
        // Missing blob.
        self::assertNull($this->resolver()->url('nope00000000'));
    }
}
