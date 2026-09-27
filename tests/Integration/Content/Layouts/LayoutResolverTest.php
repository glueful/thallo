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

    private function saveDirect(int $expected, ?array $blocks = null): void
    {
        $lock = $this->container()->get(LayoutWriteLock::class);
        $repo = $this->container()->get(LayoutRepository::class);
        $lock->within('entry', 'post', fn (): int => $blocks === null
            ? $repo->tombstone('entry', 'post', $expected, null)
            : $repo->saveExpected('entry', 'post', $blocks, ['width' => 'contained'], $expected, null));
    }

    protected function tearDown(): void
    {
        $this->resolver()->forget('entry', 'post');
        parent::tearDown();
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
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Old'], 'settings' => []]]);
        $resolver = null;
        $straddle = function (): void {
            // Between the render's read and its cache write: another request saves and forgets.
            $this->saveDirect(1, [['type' => 'heading', 'data' => ['text' => 'New'], 'settings' => []]]);
            $this->resolver()->forget('entry', 'post');
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
        $this->resolver()->forget('entry', 'post');
        $render = new LayoutResolver(
            $slow,
            $this->container()->get(\Glueful\Cache\CacheStore::class),
            $this->container()->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class),
            $this->appContext(),
        );
        self::assertSame('Old', $render->for('entry', 'post')['blocks'][0]['data']['text'], 'it read before the save');
        self::assertSame('New', $this->resolver()->for('entry', 'post')['blocks'][0]['data']['text']);
    }
}
