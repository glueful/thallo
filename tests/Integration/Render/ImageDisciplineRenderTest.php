<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Storefront-performance spec §4/§5: the per-template image discipline — at most one
 * fetchpriority image, first eligible wins, logos/regions never claim, plain-image parity
 * when no variants exist, and non-image assets are omitted.
 */
final class ImageDisciplineRenderTest extends AppTestCase
{
    /** @param list<array<string,mixed>> $list */
    private function renderBlocks(array $list, ?string $regionSlug = null): string
    {
        $env = $this->container()->get(TwigFactory::class)->environment();
        /** @var RenderContextExtension $ext */
        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        $ext->setBlockAnnotations(false);
        $ext->setLocale('en');
        return $ext->blocks(
            $env,
            ['entry' => null, 'site' => ['name' => 'T'], 'region_slug' => $regionSlug],
            $list,
        );
    }

    private function seedBlob(string $uuid, string $mime, string $visibility = 'public'): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'discip-test-' . $uuid,
            'mime_type' => $mime,
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => $visibility,
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testTwoHerosYieldExactlyOneFetchpriorityHigh(): void
    {
        $this->seedBlob('discipimg001', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'A', 'image' => 'discipimg001']],
            ['id' => 'h2', 'type' => 'hero', 'data' => ['title' => 'B', 'image' => 'discipimg001']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
        self::assertStringContainsString('loading="lazy" decoding="async"', $html);
        // glueful/media IS a Thallo dependency, so the container resolver is capable:
        // real ?width= candidates at the hero's pinned widths, plus the sizes hint
        // matching the theme's 40rem hero media cap. (Plain-image parity — the
        // srcset:null outcome — is pinned by its own render test below.)
        self::assertStringContainsString('?width=640 640w', $html);
        self::assertStringContainsString('?width=1920 1920w', $html);
        self::assertStringContainsString('sizes="(max-width: 48rem) 100vw, 40rem"', $html);
    }

    public function testNullSrcsetRendersAPlainImgWithNoSrcsetAttribute(): void
    {
        // Plain-image parity (spec §9): when the resolver's clamp-exhausted/incapable
        // outcome yields {src, srcset: null}, templates must emit NO srcset attribute
        // at all (not an empty one). The container resolver is capable in this suite,
        // so the null-srcset outcome is wired through the contract seam instead.
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return '/api/blobs/' . $uuid;
            }
        };
        $variants = new class implements MediaVariantUrlResolver {
            public function variants(string $uuid, array $widths): ?array
            {
                return ['src' => '/api/blobs/' . $uuid, 'srcset' => null];
            }
        };
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            mediaVariants: $variants,
        );
        $base = $this->appContext()->getBasePath();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $ext->resetPerRenderState();

        $html = $ext->blocks($env, ['entry' => null, 'site' => ['name' => 'T'], 'region_slug' => null], [
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'A', 'image' => 'parityimg001']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'parityimg001']],
        ]);
        self::assertSame(2, substr_count($html, '<img'), 'both templates keep the plain <img>');
        self::assertStringNotContainsString('srcset', $html);
        self::assertStringNotContainsString('sizes=', $html);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'), 'parity does not disturb the claim');
    }

    public function testImageBlockFirstClaimsWhenNoHeroExists(): void
    {
        $this->seedBlob('discipimg002', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipimg002']],
            ['id' => 'i2', 'type' => 'image', 'data' => ['image' => 'discipimg002']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
    }

    public function testRegionRenderedImageBlockNeverClaims(): void
    {
        $this->seedBlob('discipimg003', 'image/jpeg');
        $html = $this->renderBlocks(
            [['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipimg003']]],
            regionSlug: 'footer',
        );
        self::assertStringNotContainsString('fetchpriority', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testUnresolvableFirstImageDoesNotConsumeTheClaim(): void
    {
        $this->seedBlob('discipimg004', 'image/jpeg');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discipnope99']],
            ['id' => 'i2', 'type' => 'image', 'data' => ['image' => 'discipimg004']],
        ]);
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'));
    }

    public function testNonImageAssetIsOmittedEntirely(): void
    {
        $this->seedBlob('discippdf001', 'application/pdf');
        $html = $this->renderBlocks([
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'discippdf001']],
        ]);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testLogoBlockLoadingIsPositional(): void
    {
        // Site logo wiring (BlockLibraryRenderTest's container path): a public blob
        // referenced by the site_logo setting, resolved via EngineSiteLogoProvider.
        $this->seedBlob('disciplogo01', 'image/png');
        $this->container()->get(SettingsStore::class)->putMany(['site_logo' => 'disciplogo01']);

        // Body render: brand imagery lazies and never claims (spec §4).
        $body = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]]);
        self::assertStringContainsString('loading="lazy"', $body);
        self::assertStringNotContainsString('fetchpriority', $body);
        // Header region render:
        $header = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]], regionSlug: 'header');
        self::assertStringContainsString('loading="eager"', $header);
        self::assertStringNotContainsString('fetchpriority', $header);
        // Footer is explicitly the lazy branch too.
        $footer = $this->renderBlocks([['id' => 'l1', 'type' => 'logo', 'data' => []]], regionSlug: 'footer');
        self::assertStringContainsString('loading="lazy"', $footer);
        self::assertStringNotContainsString('fetchpriority', $footer);
    }
}
