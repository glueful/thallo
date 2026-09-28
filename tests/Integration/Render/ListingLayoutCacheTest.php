<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Layouts\LayoutChanges;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Cached listing and archive pages follow their layout (type layouts spec §7.4, plan B, B4): a page
 * cached while it rendered through the theme is refreshed by the first save, an edit shows on the
 * next request, and a removal brings the theme's page back — each through the change announcement a
 * save makes, which purges the surface's own tag and no other surface's.
 */
final class ListingLayoutCacheTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
    }

    protected function tearDown(): void
    {
        foreach ([['listing', 'post'], ['archive', 'post:categories']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function page(string $path): string
    {
        return (string) $this->handle(Request::create($path, 'GET'))->getContent();
    }

    /** A save as the editor's Save commits one: the row, then the change announced. */
    private function save(string $surface, string $target, string $marker): void
    {
        $blocks = [
            ['id' => 'cachemark001', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'cacheloop001', 'type' => 'entry_loop', 'data' => ['card' => [
                ['id' => 'cachetitle01', 'type' => 'entry_title', 'data' => ['level' => 'h2'], 'settings' => []],
            ]], 'settings' => []],
        ];
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            $surface,
            $target,
            fn (): int => $repo->saveExpected($surface, $target, $blocks, [], $repo->version($surface, $target), null),
        );
        $this->container()->get(LayoutChanges::class)->announce($surface, $target);
    }

    private function remove(string $surface, string $target): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            $surface,
            $target,
            fn (): int => $repo->tombstone($surface, $target, $repo->version($surface, $target), null),
        );
        $this->container()->get(LayoutChanges::class)->announce($surface, $target);
    }

    public function testAListingPageFollowsItsLayoutThroughTheCache(): void
    {
        $this->assertLifecycle('listing', 'post', '/post');
    }

    public function testAnArchivePageFollowsItsLayoutThroughTheCache(): void
    {
        $this->assertLifecycle('archive', 'post:categories', '/post/categories/pottery');
    }

    public function testAListingSaveLeavesTheArchivesCachedPage(): void
    {
        $this->save('archive', 'post:categories', 'ARCHIVE-ONE');
        self::assertStringContainsString('ARCHIVE-ONE', $this->page('/post/categories/pottery'), 'now cached');
        // The archive's layout changes without an announcement: only a purge of its own tag would
        // show it (the resolver is told, so a fresh render would).
        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = $repo->find('archive', 'post:categories')['blocks'];
        $blocks[0]['data']['text'] = 'ARCHIVE-TWO';
        $this->container()->get(LayoutWriteLock::class)->within(
            'archive',
            'post:categories',
            fn (): int => $repo->saveExpected(
                'archive',
                'post:categories',
                $blocks,
                [],
                $repo->version('archive', 'post:categories'),
                null,
            ),
        );
        $this->container()->get(LayoutResolver::class)->forget('archive', 'post:categories');

        $this->save('listing', 'post', 'LISTING-SAVED');
        self::assertStringContainsString('LISTING-SAVED', $this->page('/post'));
        self::assertStringContainsString(
            'ARCHIVE-ONE',
            $this->page('/post/categories/pottery'),
            'the listing\'s save purged its own pages, not the archive\'s',
        );
    }

    private function assertLifecycle(string $surface, string $target, string $path): void
    {
        $theme = $this->page($path);
        self::assertStringContainsString('<ul class="listing-rows">', $theme, 'today\'s page, now cached');
        self::assertStringNotContainsString('LAYOUT-ONE', $theme);

        $this->save($surface, $target, 'LAYOUT-ONE');
        self::assertStringContainsString('LAYOUT-ONE', $this->page($path), 'the first save purged the theme page');

        $this->save($surface, $target, 'LAYOUT-TWO');
        $edited = $this->page($path);
        self::assertStringContainsString('LAYOUT-TWO', $edited, 'an edit shows on the next request');
        self::assertStringNotContainsString('LAYOUT-ONE', $edited);

        $this->remove($surface, $target);
        $back = $this->page($path);
        self::assertStringNotContainsString('LAYOUT-TWO', $back);
        self::assertStringContainsString('<ul class="listing-rows">', $back, 'the theme\'s page returns');
    }
}
