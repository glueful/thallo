<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineMediaVariantUrlResolver;
use App\Tests\Support\AppTestCase;

/**
 * Storefront-performance spec §3: the batch variant resolver's three pinned outcomes,
 * clamp filtering, and single-lookup composition.
 */
final class MediaVariantUrlResolverTest extends AppTestCase
{
    private function seedBlob(string $uuid, string $mime, string $visibility = 'public'): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'variant-test-' . $uuid,
            'mime_type' => $mime,
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => $visibility,
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function resolver(bool $capable = true, int $maxWidth = 2048): EngineMediaVariantUrlResolver
    {
        return new EngineMediaVariantUrlResolver(
            $this->connection(),
            '/api/blobs',
            uploadsEnabled: true,
            accessMode: false,
            maxWidth: $maxWidth,
            resizingCapable: $capable,
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->table('blobs')->where('name', 'LIKE', 'variant-test-%')->forceDelete();
        parent::tearDown();
    }

    public function testValidImageProducesSrcAndCandidatesFromOneLookup(): void
    {
        $this->seedBlob('variantimg01', 'image/jpeg');
        $result = $this->resolver()->variants('variantimg01', [320, 640]);

        self::assertNotNull($result);
        self::assertSame('/api/blobs/variantimg01', $result['src']);
        self::assertSame(
            '/api/blobs/variantimg01?width=320 320w, /api/blobs/variantimg01?width=640 640w',
            $result['srcset']
        );
    }

    public function testNonImageBlobIsNullNeverAFallbackUrl(): void
    {
        $this->seedBlob('variantpdf01', 'application/pdf');
        self::assertNull($this->resolver()->variants('variantpdf01', [320]));
    }

    public function testMissingAndPrivateBlobsAreNull(): void
    {
        self::assertNull($this->resolver()->variants('variantnope1', [320]));
        $this->seedBlob('variantpriv1', 'image/png', visibility: 'private');
        self::assertNull($this->resolver()->variants('variantpriv1', [320]));
    }

    public function testClampDropsOversizedWidthsAndExhaustionKeepsSrc(): void
    {
        $this->seedBlob('variantimg02', 'image/webp');
        $partial = $this->resolver(maxWidth: 500)->variants('variantimg02', [320, 640]);
        self::assertSame('/api/blobs/variantimg02?width=320 320w', $partial['srcset']);

        $exhausted = $this->resolver(maxWidth: 100)->variants('variantimg02', [320, 640]);
        self::assertNotNull($exhausted, 'clamp exhaustion keeps the valid image');
        self::assertNull($exhausted['srcset'], 'null srcset — base src stands');
    }

    public function testIncapableResolverStillDistinguishesValidFromInvalid(): void
    {
        $this->seedBlob('variantimg03', 'image/png');
        $result = $this->resolver(capable: false)->variants('variantimg03', [320]);
        self::assertSame(['src' => '/api/blobs/variantimg03', 'srcset' => null], $result);

        $this->seedBlob('variantpdf02', 'application/pdf');
        self::assertNull($this->resolver(capable: false)->variants('variantpdf02', [320]));
    }
}
