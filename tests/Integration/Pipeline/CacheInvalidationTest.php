<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Events\ModelUpdated;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingArrayCache;
use Glueful\Events\EventService;

/**
 * Proves the cache-tag invalidation listener (V1_DESIGN §5) wired in
 * ThalloServiceProvider::boot() invalidates the SAME surrogate keys the delivery layer
 * emits (App\Content\Http\DeliveryEtag): `lemma:entry:{uuid}` and `lemma:type:{slug}`.
 *
 * A byte-for-byte match is the whole point — if delivery tags by slug but the listener
 * invalidates by uuid, caches go stale forever. Entry events carry the content-type
 * UUID (not slug), so the listener must resolve uuid -> slug; this test asserts the
 * resolved tag equals the delivery slug tag exactly.
 *
 * The real CacheStore singleton is swapped for a RecordingArrayCache (a real tagged
 * in-memory cache that also records every invalidateTags() call), so we assert both the
 * driver-level effect (a primed tagged value is gone) and the exact tag strings.
 */
final class CacheInvalidationTest extends AppTestCase
{
    private string $type;
    private string $entry;
    private RecordingArrayCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // Swap the container's shared `cache.store` for a recording in-memory cache. The
        // listener resolves CacheStore from the container per-invocation, so it picks up
        // this spy even though it was wired (and possibly already resolved) at boot.
        $this->cache = new RecordingArrayCache();
        $this->setSingleton('cache.store', $this->cache);

        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    protected function tearDown(): void
    {
        // Restore the real singletons so the spy never leaks into later tests.
        $this->restoreSingletons();
        parent::tearDown();
    }

    private function events(): EventService
    {
        return $this->container()->get(EventService::class);
    }

    public function testPublishInvalidatesEntryAndTypeTagsExactlyAsDeliveryEmitsThem(): void
    {
        $entryTag = 'lemma:entry:' . $this->entry;
        $typeTag = 'lemma:type:post';

        // Prime a value under each tag so we can prove the listener actually purges them.
        $this->cache->set('delivery:item:' . $this->entry, ['body' => 'cached']);
        $this->cache->addTags('delivery:item:' . $this->entry, [$entryTag, $typeTag]);
        self::assertTrue($this->cache->has('delivery:item:' . $this->entry));

        $this->container()->get(PublishService::class)->publish($this->entry, 'en', 'user00000001');

        // Effect: the tagged value is gone.
        self::assertFalse(
            $this->cache->has('delivery:item:' . $this->entry),
            'publishing must purge the cached delivery item via its tags'
        );

        // Exact tags: the listener invalidated BOTH the entry tag and the SLUG-based type
        // tag (the event carried the type UUID, so this proves uuid -> slug resolution).
        $invalidated = $this->cache->allInvalidatedTags();
        self::assertContains($entryTag, $invalidated, 'entry tag must be invalidated');
        self::assertContains($typeTag, $invalidated, 'type SLUG tag must be invalidated');
        self::assertNotContains(
            'lemma:type:' . $this->type,
            $invalidated,
            'the type tag must use the slug, never the content-type UUID'
        );
    }

    public function testModelChangeInvalidatesTypeSlugTag(): void
    {
        $typeTag = 'lemma:type:post';
        $this->cache->set('delivery:list:post', ['items' => []]);
        $this->cache->addTags('delivery:list:post', [$typeTag]);

        // Model events already carry the slug.
        $this->events()->dispatch(new ModelUpdated(type: 'post', actor: 'user00000001'));

        self::assertFalse(
            $this->cache->has('delivery:list:post'),
            'a model change must purge the type-tagged delivery cache'
        );
        self::assertContains($typeTag, $this->cache->allInvalidatedTags());
    }

    // ---- container surgery (the compiled container exposes no setter) ----------------

    /** @var array<string, array{0: bool, 1: mixed}> id => [existed, priorValue] */
    private array $priorSingletons = [];

    private function restoreSingletons(): void
    {
        foreach (array_reverse($this->priorSingletons, true) as $id => [$existed, $value]) {
            $this->writeSingleton($id, $existed, $value);
        }
        $this->priorSingletons = [];
    }

    private function setSingleton(string $id, mixed $value): void
    {
        $this->stashSingleton($id);
        $this->writeSingleton($id, true, $value);
    }

    private function stashSingleton(string $id): void
    {
        if (array_key_exists($id, $this->priorSingletons)) {
            return;
        }
        $singletons = $this->singletons();
        $this->priorSingletons[$id] = [
            array_key_exists($id, $singletons),
            $singletons[$id] ?? null,
        ];
    }

    private function writeSingleton(string $id, bool $present, mixed $value): void
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        if ($present) {
            $singletons[$id] = $value;
        } else {
            unset($singletons[$id]);
        }
        $prop->setValue($container, $singletons);
    }

    /** @return array<string, mixed> */
    private function singletons(): array
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        return $singletons;
    }
}
