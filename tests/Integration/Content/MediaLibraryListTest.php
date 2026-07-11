<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Http\Controllers\MediaAdminController;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * The library list must show blobs that have no `media_assets` ownership row.
 *
 * `media_assets` is the tenant ledger, written only by {@see \App\Content\Media\TenantBlobPolicy}
 * when tenancy is on (or in compat mode). With tenancy off, every uploaded blob lacks a ledger row,
 * so an inner join to `media_assets` hid the entire library ("No media"). The list must key its
 * source table off enablement: `blobs` directly when off, `media_assets`-primary when on.
 */
final class MediaLibraryListTest extends AppTestCase
{
    /** Insert a blobs row directly, with NO matching media_assets ledger row (tenancy-off upload). */
    private function seedBlob(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/pic.jpg',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    public function testListsBlobsWithoutAnOwnershipLedgerRowWhenTenancyIsOff(): void
    {
        $uuid = $this->seedBlob();

        $controller = $this->container()->get(MediaAdminController::class);
        self::assertInstanceOf(MediaAdminController::class, $controller);

        $response = $controller->index(Request::create('/v1/admin/media', 'GET', ['page' => 1, 'per_page' => 30]));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        /** @var array<string,mixed> $data */
        $data = $payload['data'] ?? [];
        $ids = array_column(is_array($data['media'] ?? null) ? $data['media'] : [], 'uuid');

        self::assertContains($uuid, $ids, 'A blob without a media_assets row must appear while tenancy is off.');
        self::assertGreaterThanOrEqual(1, $data['total'] ?? 0);
    }
}
