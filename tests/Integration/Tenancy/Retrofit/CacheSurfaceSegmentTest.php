<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Preview\PreviewWorkingCopyStore;
use App\Tests\Support\RetrofittedTenantTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderErrorCache;
use Thallo\Seo\Cache\SitemapCache;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\System\SystemFlags;

final class CacheSurfaceSegmentTest extends RetrofittedTenantTestCase
{
    public function testTenantSegmentsAndCacheSurfacesAreIsolated(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $segments = $this->container()->get(TenantCacheSegment::class);
        $a = $this->runAsTenant(
            self::$tenantAUuid,
            fn (): string => $segments->segment($this->appContext(), 'render'),
        );
        $b = $this->runAsTenant(
            self::$tenantBUuid,
            fn (): string => $segments->segment($this->appContext(), 'render'),
        );
        self::assertNotSame('', $a);
        self::assertNotSame($a, $b);

        $this->assertRenderPageIsolation();
        $this->assertErrorCacheIsolation();
        $this->assertSitemapIsolation();
        $this->assertPreviewIsolation();
    }

    private function assertRenderPageIsolation(): void
    {
        $cache = $this->container()->get(RenderPageCache::class);
        $request = Request::create('/same-path');

        $bodyA = $this->runAsTenant(self::$tenantAUuid, fn (): string => (string) $cache
            ->handle($request, static fn (): Response => new Response('tenant-a', 200, ['Content-Type' => 'text/html']))
            ->getContent());
        $bodyB = $this->runAsTenant(self::$tenantBUuid, fn (): string => (string) $cache
            ->handle($request, static fn (): Response => new Response('tenant-b', 200, ['Content-Type' => 'text/html']))
            ->getContent());

        self::assertSame('tenant-a', $bodyA);
        self::assertSame('tenant-b', $bodyB);
    }

    private function assertErrorCacheIsolation(): void
    {
        $cache = $this->container()->get(RenderErrorCache::class);
        $responseA = $this->runAsTenant(self::$tenantAUuid, fn (): Response => $cache->themed404(
            static fn (): Response => new Response('missing-a', 404, ['Content-Type' => 'text/html']),
        ));
        $responseB = $this->runAsTenant(self::$tenantBUuid, fn (): Response => $cache->themed404(
            static fn (): Response => new Response('missing-b', 404, ['Content-Type' => 'text/html']),
        ));

        self::assertSame('missing-a', $responseA->getContent());
        self::assertSame('missing-b', $responseB->getContent());
    }

    private function assertSitemapIsolation(): void
    {
        $cache = $this->container()->get(SitemapCache::class);
        $valueA = $this->runAsTenant(
            self::$tenantAUuid,
            fn (): string => $cache->remember('thallo:seo:sitemap:test', static fn (): string => 'map-a'),
        );
        $valueB = $this->runAsTenant(
            self::$tenantBUuid,
            fn (): string => $cache->remember('thallo:seo:sitemap:test', static fn (): string => 'map-b'),
        );

        self::assertSame('map-a', $valueA);
        self::assertSame('map-b', $valueB);
    }

    private function assertPreviewIsolation(): void
    {
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $this->runAsTenant(
            self::$tenantAUuid,
            static function () use ($store): void {
                $store->put('entry0000001', 'en', ['title' => 'A'], 60);
            },
        );
        $this->runAsTenant(
            self::$tenantBUuid,
            static function () use ($store): void {
                $store->put('entry0000001', 'en', ['title' => 'B'], 60);
            },
        );

        self::assertSame(
            ['title' => 'A'],
            $this->runAsTenant(self::$tenantAUuid, static fn (): ?array => $store->get('entry0000001', 'en')),
        );
        self::assertSame(
            ['title' => 'B'],
            $this->runAsTenant(self::$tenantBUuid, static fn (): ?array => $store->get('entry0000001', 'en')),
        );
    }
}
