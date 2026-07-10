<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Media\TenantBlobPolicy;
use App\Http\Controllers\MediaAdminController;
use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAction;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\System\SystemFlags;

final class MediaOwnershipTest extends RetrofittedTenantTestCase
{
    public function testOwnershipIsImmutableAndAdminListingIsTenantScoped(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $policy = $this->container()->get(TenantBlobPolicy::class);
        $blobA = $this->seedBlob('mediaA000001', 'tenant-a.png');
        $blobB = $this->seedBlob('mediaB000001', 'tenant-b.png');
        $orphan = $this->seedBlob('mediaO000001', 'orphan.png');

        $this->runAsTenant(
            self::$tenantAUuid,
            static function () use ($policy, $blobA): void {
                $policy->onBlobCreated($blobA['uuid'], 'user00000001');
            },
        );
        $this->runAsTenant(
            self::$tenantBUuid,
            static function () use ($policy, $blobB): void {
                $policy->onBlobCreated($blobB['uuid'], 'user00000001');
            },
        );

        self::assertTrue($this->runAsTenant(
            self::$tenantAUuid,
            static fn (): bool => $policy->authorizeAccess(
                $blobA,
                new BlobAccessContext(BlobAction::VIEW, null, true),
            ),
        ));
        self::assertFalse($this->runAsTenant(
            self::$tenantAUuid,
            static fn (): bool => $policy->authorizeAccess(
                $blobB,
                new BlobAccessContext(BlobAction::VIEW, null, true),
            ),
        ));
        self::assertFalse($this->runAsTenant(
            self::$tenantAUuid,
            static fn (): bool => $policy->authorizeAccess(
                $orphan,
                new BlobAccessContext(BlobAction::VIEW, null, true),
            ),
        ));

        $threw = false;
        try {
            $this->runAsTenant(
                self::$tenantAUuid,
                static function () use ($policy, $blobB): void {
                    $policy->onBlobCreated($blobB['uuid'], 'user00000001');
                },
            );
        } catch (RuntimeException) {
            $threw = true;
        }
        self::assertTrue($threw);
        self::assertSame(self::$tenantBUuid, $this->rawOwner($blobB['uuid']));

        $controller = $this->container()->get(MediaAdminController::class);
        $namesA = $this->runAsTenant(
            self::$tenantAUuid,
            fn (): array => $this->mediaNames($controller),
        );
        $namesB = $this->runAsTenant(
            self::$tenantBUuid,
            fn (): array => $this->mediaNames($controller),
        );

        self::assertSame(['tenant-a.png'], $namesA);
        self::assertSame(['tenant-b.png'], $namesB);
    }

    /** @return array<string,mixed> */
    private function seedBlob(string $uuid, string $name): array
    {
        $row = [
            'uuid' => $uuid,
            'name' => $name,
            'mime_type' => 'image/png',
            'size' => 1,
            'url' => 'uploads/' . $name,
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->connection()->table('blobs')->insert($row);

        return $row;
    }

    private function rawOwner(string $blobUuid): string
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT tenant_uuid FROM media_assets WHERE blob_uuid = :blob'
        );
        $statement->execute(['blob' => $blobUuid]);

        return (string) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function mediaNames(MediaAdminController $controller): array
    {
        $response = $controller->index(Request::create('https://site.test/v1/admin/media'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_column($body['data']['media'], 'name');
    }
}
