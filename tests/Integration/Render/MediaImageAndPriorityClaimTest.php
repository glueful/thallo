<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Render\RenderContextExtension;

/**
 * Storefront-performance spec §3/§4: the unified media_image() helper's outcome table and
 * the at-most-one, first-eligible, region-excluded priority claim with its per-render
 * reset.
 */
final class MediaImageAndPriorityClaimTest extends AppTestCase
{
    /** Extension with FAKE resolvers — media_image()'s branching is what's under test. */
    private function extension(?bool $variantResolver): RenderContextExtension
    {
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return $uuid === 'missing00000' ? null : '/api/blobs/' . $uuid;
            }
        };
        $variants = $variantResolver === null ? null : new class implements MediaVariantUrlResolver {
            public function variants(string $uuid, array $widths): ?array
            {
                if ($uuid === 'nonimage0000' || $uuid === 'missing00000') {
                    return null;
                }
                if ($uuid === 'clamped00000') {
                    return ['src' => '/api/blobs/' . $uuid, 'srcset' => null];
                }
                return ['src' => '/api/blobs/' . $uuid, 'srcset' => '/api/blobs/' . $uuid . '?width=320 320w'];
            }
        };
        return new RenderContextExtension(
            null,
            $this->container()->get(\Thallo\Contracts\Delivery\EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            mediaVariants: $variants,
        );
    }

    public function testMediaImageWithoutResolverFallsBackToPlainMedia(): void
    {
        $ext = $this->extension(variantResolver: null);
        self::assertSame(
            ['src' => '/api/blobs/plainimg0001', 'srcset' => null],
            $ext->mediaImage('plainimg0001', [320]),
        );
        self::assertNull($ext->mediaImage('missing00000', [320]));
    }

    public function testMediaImageWithResolverHonorsTheThreeOutcomes(): void
    {
        $ext = $this->extension(variantResolver: true);
        $candidate = $ext->mediaImage('goodimg00001', [320]);
        self::assertNotNull($candidate);
        self::assertSame('/api/blobs/goodimg00001', $candidate['src']);
        self::assertSame('/api/blobs/goodimg00001?width=320 320w', $candidate['srcset']);
        self::assertNull($ext->mediaImage('nonimage0000', [320]), 'non-image omitted, never a media() fallback');
        self::assertSame(
            ['src' => '/api/blobs/clamped00000', 'srcset' => null],
            $ext->mediaImage('clamped00000', [320]),
        );
    }

    public function testPriorityClaimIsAtMostOncePerRenderAndRegionExcluded(): void
    {
        $ext = $this->extension(variantResolver: null);
        $ext->resetPerRenderState();

        self::assertFalse($ext->claimPriorityImage(['region_slug' => 'footer']), 'region renders never claim');
        self::assertTrue($ext->claimPriorityImage(['region_slug' => null]), 'first body claimant wins');
        self::assertFalse($ext->claimPriorityImage(['region_slug' => null]), 'second body claimant loses');

        $ext->resetPerRenderState();
        self::assertTrue($ext->claimPriorityImage([]), 'a fresh render gets a fresh claim');
    }
}
