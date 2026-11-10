<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Delivery\EngineMediaVariantUrlResolver;
use Thallo\Core\Tests\Support\AppTestCase;

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

    private function resolver(
        bool $capable = true,
        int $maxWidth = 2048,
        bool $webp = false,
    ): EngineMediaVariantUrlResolver {
        return new EngineMediaVariantUrlResolver(
            $this->connection(),
            '/api/blobs',
            uploadsEnabled: true,
            accessMode: false,
            maxWidth: $maxWidth,
            resizingCapable: $capable,
            webpCapable: $webp,
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

    public function testSvgImageKeepsSrcButNeverGetsResizeCandidates(): void
    {
        // image/* but NOT in the raster set UploadController::formatFromMime decodes:
        // a ?width= candidate would 500 at serve time, and browsers do not fall back
        // to src when a chosen srcset candidate fails. Valid image, no candidates.
        $this->seedBlob('variantsvg01', 'image/svg+xml');
        $result = $this->resolver()->variants('variantsvg01', [320, 640]);
        self::assertSame(['src' => '/api/blobs/variantsvg01', 'srcset' => null, 'webp' => null], $result);
    }

    public function testIncapableResolverStillDistinguishesValidFromInvalid(): void
    {
        $this->seedBlob('variantimg03', 'image/png');
        $result = $this->resolver(capable: false)->variants('variantimg03', [320]);
        self::assertSame(['src' => '/api/blobs/variantimg03', 'srcset' => null, 'webp' => null], $result);

        $this->seedBlob('variantpdf02', 'application/pdf');
        self::assertNull($this->resolver(capable: false)->variants('variantpdf02', [320]));
    }

    public function testJpegAndPngGainWebpCandidatesWhereTheServerEncodesWebp(): void
    {
        $this->seedBlob('variantimg04', 'image/png');
        $result = $this->resolver(webp: true)->variants('variantimg04', [320, 640]);

        self::assertSame(
            '/api/blobs/variantimg04?width=320 320w, /api/blobs/variantimg04?width=640 640w',
            $result['srcset'],
            'the original format stays the <img> fallback',
        );
        self::assertSame(
            '/api/blobs/variantimg04?width=320&format=webp 320w, /api/blobs/variantimg04?width=640&format=webp 640w',
            $result['webp'],
        );
        self::assertNull($this->resolver()->variants('variantimg04', [320])['webp'], 'no WebP encoder: none');
    }

    public function testWebpAndGifSourcesGetNoWebpCandidates(): void
    {
        // A WebP is already WebP; a GIF may be animated, and a converted frame would freeze it.
        $this->seedBlob('variantimg05', 'image/webp');
        $this->seedBlob('variantgif01', 'image/gif');
        self::assertNull($this->resolver(webp: true)->variants('variantimg05', [320])['webp']);
        self::assertNull($this->resolver(webp: true)->variants('variantgif01', [320])['webp']);
        self::assertNotNull($this->resolver(webp: true)->variants('variantgif01', [320])['srcset']);
    }

    public function testManyResolvesEveryUuidInRequestOrderWithNullForWhatThePageMayNotShow(): void
    {
        $this->seedBlob('variantimg06', 'image/jpeg');
        $this->seedBlob('variantpriv2', 'image/jpeg', visibility: 'private');
        $this->seedBlob('variantimg07', 'image/png');

        $many = $this->resolver(webp: true)->variantsMany(
            ['variantimg07', 'variantpriv2', 'variantnope2', 'variantimg06', 'variantimg07'],
            [320],
        );

        self::assertSame(['variantimg07', 'variantpriv2', 'variantnope2', 'variantimg06'], array_keys($many));
        self::assertNull($many['variantpriv2']);
        self::assertNull($many['variantnope2']);
        self::assertSame($this->resolver(webp: true)->variants('variantimg06', [320]), $many['variantimg06']);
        self::assertSame('/api/blobs/variantimg07?width=320&format=webp 320w', $many['variantimg07']['webp']);
    }
}
