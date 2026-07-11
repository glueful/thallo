<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Retrofit\MediaOwnershipBackfill;

final class MediaOwnershipBackfillTest extends AppTestCase
{
    public function testBackfillAttributesExistingBlobAndForeignKeyCascades(): void
    {
        $blobUuid = Utils::generateNanoID(12);
        $tenantUuid = Utils::generateNanoID(12);
        $this->connection()->table('blobs')->insert([
            'uuid' => $blobUuid,
            'name' => 'legacy.png',
            'mime_type' => 'image/png',
            'size' => 1,
            'url' => 'legacy.png',
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertSame(1, $this->container()->get(MediaOwnershipBackfill::class)->run($tenantUuid));
        $ownership = $this->connection()->table('media_assets')
            ->where('blob_uuid', '=', $blobUuid)
            ->first();
        self::assertSame($tenantUuid, $ownership['tenant_uuid'] ?? null);

        $this->connection()->table('blobs')->where('uuid', '=', $blobUuid)->forceDelete();
        self::assertSame(0, $this->connection()->table('media_assets')->where('blob_uuid', '=', $blobUuid)->count());
    }
}
