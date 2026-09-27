<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The layout reader (type layouts spec §7.4): one cached answer per subject — a layout, or "none" —
 * so a render never queries the table twice for the same page kind. Writers forget the answer
 * after committing, so a first layout or a new version is found on the next render.
 */
final class LayoutResolverTest extends AppTestCase
{
    private function resolver(): LayoutResolver
    {
        return $this->container()->get(LayoutResolver::class);
    }

    private function saveDirect(
        int $expected,
        ?array $blocks = null,
        string $surface = 'entry',
        string $target = 'post',
    ): void {
        $lock = $this->container()->get(LayoutWriteLock::class);
        $repo = $this->container()->get(LayoutRepository::class);
        $lock->within($surface, $target, fn (): int => $blocks === null
            ? $repo->tombstone($surface, $target, $expected, null)
            : $repo->saveExpected($surface, $target, $blocks, ['width' => 'contained'], $expected, null));
    }

    protected function tearDown(): void
    {
        $this->resolver()->forget('entry', 'post');
        $this->resolver()->forget('fixture', '@site');
        parent::tearDown();
    }

    /**
     * A site-wide target (`@site`, type layouts spec §5.1) is part of the cache key, and the Redis
     * driver refuses `{}()/\@` in keys: the target is encoded, and an entry key reads as before.
     */
    public function testTheKeyOfASiteWideTargetIsAValidCacheKey(): void
    {
        foreach (['', 'tenant:tnta:'] as $prefix) {
            $key = LayoutResolver::cacheKey($prefix, 'product', '@site');
            self::assertFalse(strpbrk($key, '{}()/\\@'), $key);
            self::assertStringStartsWith($prefix . 'thallo:layout:product:', $key);
        }
        self::assertSame('thallo:layout:entry:post', LayoutResolver::cacheKey('', 'entry', 'post'));
    }

    public function testASiteWideLayoutIsFoundAfterForget(): void
    {
        $this->resolver()->forget('fixture', '@site');
        self::assertNull($this->resolver()->for('fixture', '@site'));
        $site = [['type' => 'heading', 'data' => ['text' => 'Site'], 'settings' => []]];
        $this->saveDirect(0, $site, 'fixture', '@site');
        $this->resolver()->forget('fixture', '@site');
        self::assertSame('Site', $this->resolver()->for('fixture', '@site')['blocks'][0]['data']['text']);

        // The generation scheme is the entry surface's: a counter under `:gen`, the answer under `:g{n}`.
        $cache = $this->container()->get(\Glueful\Cache\CacheStore::class);
        $segment = $this->container()->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class)
            ->segment($this->appContext(), 'layouts');
        $key = LayoutResolver::cacheKey($segment, 'fixture', '@site');
        $generation = (int) $cache->get($key . ':gen');
        self::assertGreaterThan(0, $generation);
        self::assertSame('Site', $cache->get($key . ':g' . $generation)['layout']['blocks'][0]['data']['text']);
    }

    public function testTheResolverIsTheBoundLayoutReader(): void
    {
        self::assertInstanceOf(LayoutResolver::class, $this->container()->get(LayoutReader::class));
    }

    public function testNoneIsCachedAndAFirstSaveIsFoundAfterForget(): void
    {
        $this->resolver()->forget('entry', 'post');
        self::assertNull($this->resolver()->for('entry', 'post'));
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => []]]);
        self::assertNull($this->resolver()->for('entry', 'post'), 'the cached "none" stands until forgotten');
        $this->resolver()->forget('entry', 'post');
        $layout = $this->resolver()->for('entry', 'post');
        self::assertSame('Hi', $layout['blocks'][0]['data']['text']);
        self::assertSame(['width' => 'contained'], $layout['settings']);
        self::assertSame(1, $layout['lock_version']);
    }

    public function testATombstoneReadsAsNone(): void
    {
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => []]]);
        $this->saveDirect(1);
        $this->resolver()->forget('entry', 'post');
        self::assertNull($this->resolver()->for('entry', 'post'));
    }

    /**
     * A render that read the old row before a save committed must not put it back in the cache
     * after the save's `forget()`: the next render finds the new version, not after the TTL.
     */
    public function testARenderStraddlingASaveNeverCachesTheOldLayout(): void
    {
        $this->assertAStraddlingRenderNeverCachesTheOldLayout('entry', 'post');
    }

    /** The same protection for a site-wide target, whose key is encoded. */
    public function testASiteWideRenderStraddlingASaveNeverCachesTheOldLayout(): void
    {
        $this->assertAStraddlingRenderNeverCachesTheOldLayout('fixture', '@site');
    }

    private function assertAStraddlingRenderNeverCachesTheOldLayout(string $surface, string $target): void
    {
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Old'], 'settings' => []]], $surface, $target);
        $straddle = function () use ($surface, $target): void {
            // Between the render's read and its cache write: another request saves and forgets.
            $new = [['type' => 'heading', 'data' => ['text' => 'New'], 'settings' => []]];
            $this->saveDirect(1, $new, $surface, $target);
            $this->resolver()->forget($surface, $target);
        };
        $slow = new class (
            $this->connection(),
            $this->container()->get(LayoutWriteLock::class),
            $straddle,
        ) extends LayoutRepository {
            private bool $once = true;

            public function __construct(
                \Glueful\Database\Connection $db,
                LayoutWriteLock $lock,
                private readonly \Closure $after,
            ) {
                parent::__construct($db, $lock);
            }

            public function find(string $surface, string $target): ?array
            {
                $row = parent::find($surface, $target);
                if ($this->once) {
                    $this->once = false;
                    ($this->after)();
                }
                return $row;
            }
        };
        $this->resolver()->forget($surface, $target);
        $render = new LayoutResolver(
            $slow,
            $this->container()->get(\Glueful\Cache\CacheStore::class),
            $this->container()->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class),
            $this->appContext(),
        );
        $read = $render->for($surface, $target)['blocks'][0]['data']['text'];
        self::assertSame('Old', $read, 'it read before the save');
        self::assertSame('New', $this->resolver()->for($surface, $target)['blocks'][0]['data']['text']);
    }
}
