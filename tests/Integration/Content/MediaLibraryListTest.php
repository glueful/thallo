<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Http\Controllers\MediaAdminController;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * The library list must show blobs that have no `media_assets` ownership row.
 *
 * `media_assets` is the tenant ledger, written only by {@see \Thallo\Core\Content\Media\TenantBlobPolicy}
 * when tenancy is on (or in compat mode). With tenancy off, every uploaded blob lacks a ledger row,
 * so an inner join to `media_assets` hid the entire library ("No media"). The list must key its
 * source table off enablement: `blobs` directly when off, `media_assets`-primary when on.
 */
final class MediaLibraryListTest extends AppTestCase
{
    /** Insert a blobs row directly, with NO matching media_assets ledger row (tenancy-off upload). */
    private function seedBlob(string $name = 'pic.jpg', string $mime = 'image/jpeg'): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $name,
            'mime_type' => $mime,
            'size' => 123,
            'url' => 'uploads/' . $name,
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

    public function testSearchIgnoresCaseAndTakesWildcardsLiterally(): void
    {
        $harbour = $this->seedBlob('Harbour-At-Dusk.jpg');
        $percent = $this->seedBlob('50%_off.png');
        $other = $this->seedBlob('fifty-off.png');
        $controller = $this->container()->get(MediaAdminController::class);
        $search = function (string $q) use ($controller): array {
            $response = $controller->index(Request::create('/v1/admin/media', 'GET', ['q' => $q, 'per_page' => 30]));
            $data = json_decode((string) $response->getContent(), true)['data'] ?? [];

            return array_column($data['media'] ?? [], 'uuid');
        };

        self::assertContains($harbour, $search('harbour'));
        self::assertSame([$percent], array_values(array_intersect([$percent, $other], $search('50%_'))));
    }

    public function testFontsAreATypeOfTheirOwn(): void
    {
        // A site's own typefaces live in the media library: the font picker lists only them, and
        // they do not crowd the documents.
        $font = $this->seedBlob('brand.woff2', 'font/woff2');
        $image = $this->seedBlob('pic.jpg', 'image/jpeg');
        $pdf = $this->seedBlob('terms.pdf', 'application/pdf');
        $list = function (string $type): array {
            $res = $this->container()->get(MediaAdminController::class)->index(
                Request::create('/v1/admin/media', 'GET', ['page' => 1, 'per_page' => 30, 'type' => $type]),
            );
            return array_column((array) json_decode((string) $res->getContent(), true)['data']['media'], 'uuid');
        };
        self::assertSame([$font], $list('font'));
        self::assertContains($pdf, $list('doc'));
        self::assertNotContains($font, $list('doc'));
        self::assertNotContains($image, $list('font'));

        // And the uploader takes them: woff2, the one format every browser a theme supports reads.
        self::assertContains('font/woff2', (array) config($this->appContext(), 'uploads.allowed_types'));
    }

    /** @return list<array<string,mixed>> */
    private function listRows(): array
    {
        $controller = $this->container()->get(MediaAdminController::class);
        self::assertInstanceOf(MediaAdminController::class, $controller);
        $response = $controller->index(Request::create('/v1/admin/media', 'GET', ['page' => 1, 'per_page' => 30]));
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        $media = $payload['data']['media'] ?? null;
        self::assertIsArray($media);

        return $media;
    }

    public function testRasterThumbnailsAskForAWidthVariant(): void
    {
        $uuid = $this->seedBlob();

        $rows = array_values(array_filter($this->listRows(), static fn (array $r): bool => $r['uuid'] === $uuid));
        self::assertCount(1, $rows);
        self::assertStringContainsString('width=160', (string) $rows[0]['thumb_url']);
    }

    public function testSvgThumbnailsAreTheOriginalNotAResizedVariant(): void
    {
        $uuid = $this->seedBlob('logo.svg', 'image/svg+xml');

        $rows = array_values(array_filter($this->listRows(), static fn (array $r): bool => $r['uuid'] === $uuid));
        self::assertCount(1, $rows);
        // There is no raster variant of a vector image: asking for one is a 422 from the resizer.
        self::assertStringNotContainsString('width=', (string) $rows[0]['thumb_url']);
        self::assertSame($rows[0]['display_url'], $rows[0]['thumb_url']);
    }
}
