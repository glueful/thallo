<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Cache\RenderCacheHints;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\RenderErrorCache;

/**
 * Product grid spec §3.2 on the themed 404/410 body: its chrome can hold a Product grid (a header
 * or footer region), so the fixed body is cached with the render's catalog tag and guards like any
 * page — refused on read once a guard moved, and never stored from a render that may not be cached.
 */
final class RenderErrorCacheGuardTest extends AppTestCase
{
    private const GEN = 'thallo:cataloggen:shop:errorguard';
    private const TAG = 'thallo:shop:catalog:errorguard';

    private RenderCacheHints $hints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hints = new RenderCacheHints();
    }

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        $this->cache()->delete(self::GEN);
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function errors(): RenderErrorCache
    {
        return new RenderErrorCache(
            $this->cache(),
            'default',
            static fn (): string => 'fp',
            true,
            3600,
            null,
            null,
            fn (): RenderCacheHints => $this->hints,
        );
    }

    /** An error render (404 by default) saying `$body` whose grid read generation `$gen`. */
    private function render(string $body, ?string $gen, bool $uncacheable = false, int $status = 404): \Closure
    {
        return function () use ($body, $gen, $uncacheable, $status): Response {
            $this->hints = new RenderCacheHints([self::TAG], $gen === null ? [] : [self::GEN => $gen], $uncacheable);
            return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        };
    }

    public function testAGuardThatMovedRefusesTheStoredBody(): void
    {
        $this->cache()->set(self::GEN, 'g1', 3600);
        self::assertSame('old grid', $this->errors()->themed404($this->render('old grid', 'g1'))->getContent());
        self::assertSame('old grid', $this->errors()->themed404($this->render('unused', 'g1'))->getContent());

        $this->cache()->set(self::GEN, 'g2', 3600); // the catalog changed
        self::assertSame('new grid', $this->errors()->themed404($this->render('new grid', 'g2'))->getContent());
    }

    public function testTheBodyCarriesTheCatalogTag(): void
    {
        $this->cache()->set(self::GEN, 'g1', 3600);
        $this->errors()->themed404($this->render('grid', 'g1'));
        $this->cache()->invalidateTags([self::TAG]);
        self::assertSame('fresh', $this->errors()->themed404($this->render('fresh', 'g1'))->getContent());
    }

    public function testAnUncacheableRenderIsNotStored(): void
    {
        $this->errors()->themed410($this->render('straddled', null, true, 410));
        self::assertSame('next', $this->errors()->themed410($this->render('next', null, false, 410))->getContent());
    }

    public function testTheBoundCacheTakesTheHintsOfTheRenderThatRan(): void
    {
        // As the controller renders 404.twig: the extension's hints reset at the render's start and
        // a Product grid in the chrome records its guard — the container's cache stores it.
        $extension = $this->container()->get(RenderContextExtension::class);
        $this->container()->get(RenderErrorCache::class)->themed404(static function () use ($extension): Response {
            $extension->resetTags();
            $extension->productGridView([]);
            return new Response('chrome with a grid', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $stored = null;
        foreach ($this->cache()->getKeys('*render:*') as $key) {
            $entry = $this->cache()->get($key);
            if (is_array($entry) && ($entry['body'] ?? null) === 'chrome with a grid') {
                $stored = $entry;
            }
        }
        self::assertIsArray($stored, 'the 404 body is cached');
        self::assertNotSame([], $stored['guards'] ?? [], 'with the catalog generation the grid read');
    }
}
