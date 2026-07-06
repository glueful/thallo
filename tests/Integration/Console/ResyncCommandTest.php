<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Content\Console\ResyncCommand;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use Thallo\Contracts\Search\ContentReindexer;
use App\Content\Services\PublishService;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingArrayCache;
use App\Tests\Support\RecordingContentReindexer;
use App\Tests\Support\RecordingWebhookDispatcher;
use Glueful\Api\Webhooks\WebhookDispatcher;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves `thallo:resync` re-drives the idempotent publishing-pipeline effects (V1_DESIGN §5)
 * for content that was published while a crash dropped the in-process afterCommit callbacks.
 *
 * The test SIMULATES a dropped afterCommit by publishing first, then swapping the cache /
 * search / webhook singletons for recording spies AFTER the publish — so the spies
 * captured NOTHING from the original publish. Running `thallo:resync` must then re-drive the
 * cache invalidation + search reindex (the re-drivable effects), prove the cache tags get
 * invalidated, and prove webhooks DO NOT re-fire unless `--webhooks` is passed.
 *
 * The command reads published entries through DeliveryRepository (leak-proof — only published
 * rows exist on its spine), so resync can never surface or act on drafts/unpublished content.
 *
 * Container surgery (reflection on the compiled container's `singletons`) mirrors
 * CacheInvalidationTest / CapabilityGatingTest.
 */
final class ResyncCommandTest extends AppTestCase
{
    private string $type;
    private RecordingArrayCache $cache;
    private RecordingContentReindexer $reindexer;
    private RecordingWebhookDispatcher $webhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreSingletons();
        parent::tearDown();
    }

    /**
     * Publish an entry of $this->type with a title, returning its uuid. The pipeline's real
     * listeners run here, but the spies are installed AFTER this returns (see installSpies),
     * so from the spies' point of view this publish "dropped" its afterCommit effects.
     */
    private function publishEntry(string $title): string
    {
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /**
     * Substitute recording spies for the effect sinks, simulating a fresh process that never
     * saw the original publish's afterCommit. Also binds a search seam so the reindex gate is
     * on (the default install has none).
     */
    private function installSpies(): void
    {
        $this->cache = new RecordingArrayCache();
        $this->setSingleton('cache.store', $this->cache);

        $this->reindexer = new RecordingContentReindexer();
        $this->setSingleton(ContentReindexer::class, $this->reindexer);

        $this->webhooks = new RecordingWebhookDispatcher();
        $this->setSingleton(WebhookDispatcher::class, $this->webhooks);
    }

    private function tester(): CommandTester
    {
        $command = new ResyncCommand($this->container(), $this->appContext());
        return new CommandTester($command);
    }

    // ---- --entry ---------------------------------------------------------------------

    public function testResyncEntryReinvalidatesCacheTagsAndEnqueuesReindex(): void
    {
        $entry = $this->publishEntry('V1');
        $this->installSpies();

        $tester = $this->tester();
        $exit = $tester->execute(['--entry' => $entry]);

        self::assertSame(0, $exit, 'resync must exit 0');

        $invalidated = $this->cache->allInvalidatedTags();
        self::assertContains('lemma:entry:' . $entry, $invalidated, 'entry tag must be re-invalidated');
        self::assertContains('lemma:type:post', $invalidated, 'type slug tag must be re-invalidated');

        self::assertCount(1, $this->reindexer->requests, 'one reindex request must be recorded for the entry');
        self::assertSame($entry, $this->reindexer->requests[0]['entry']);
        self::assertSame('en', $this->reindexer->requests[0]['locale']);

        // Webhooks are OPT-IN: default resync must NOT re-fire deliveries.
        self::assertSame([], $this->webhooks->calls, 'default resync must NOT dispatch webhooks');
    }

    // ---- --type ----------------------------------------------------------------------

    public function testResyncTypeReDrivesEveryPublishedEntryOfThatType(): void
    {
        $a = $this->publishEntry('A');
        $b = $this->publishEntry('B');
        $c = $this->publishEntry('C');
        $this->installSpies();

        $exit = $this->tester()->execute(['--type' => 'post']);
        self::assertSame(0, $exit);

        // N published entries -> N reindex requests.
        self::assertCount(3, $this->reindexer->requests, 'every published entry of the type must reindex');
        $reindexed = array_map(static fn(array $request): string => $request['entry'], $this->reindexer->requests);
        self::assertEqualsCanonicalizing([$a, $b, $c], $reindexed);

        // Each entry tag invalidated + the type tag.
        $invalidated = $this->cache->allInvalidatedTags();
        foreach ([$a, $b, $c] as $uuid) {
            self::assertContains('lemma:entry:' . $uuid, $invalidated);
        }
        self::assertContains('lemma:type:post', $invalidated);

        self::assertSame([], $this->webhooks->calls, 'default resync must NOT dispatch webhooks');
    }

    // ---- no args: everything ----------------------------------------------------------

    public function testResyncWithNoArgsReDrivesEveryPublishedEntryAcrossTypes(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $other = $types->create([
            'slug' => 'page', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);

        $post = $this->publishEntry('Post one');
        // Publish an entry of the OTHER type.
        $entries = $this->container()->get(EntryRepository::class);
        $page = $entries->createEntry($other, 'en', 1, 'user00000001');
        $entries->saveDraft($page, 'en', ['title' => 'Page one'], 1, 0, 'user00000001');
        $this->container()->get(PublishService::class)->publish($page, 'en', 'user00000001');

        $this->installSpies();

        $exit = $this->tester()->execute([]);
        self::assertSame(0, $exit);

        // Covers all published entries across every type.
        $reindexed = array_map(static fn(array $request): string => $request['entry'], $this->reindexer->requests);
        self::assertEqualsCanonicalizing([$post, $page], $reindexed, 'no-args resync covers all types');

        $invalidated = $this->cache->allInvalidatedTags();
        self::assertContains('lemma:entry:' . $post, $invalidated);
        self::assertContains('lemma:entry:' . $page, $invalidated);
        self::assertContains('lemma:type:post', $invalidated);
        self::assertContains('lemma:type:page', $invalidated);
    }

    // ---- never touches drafts ---------------------------------------------------------

    public function testResyncNeverActsOnUnpublishedEntries(): void
    {
        $published = $this->publishEntry('Published');

        // A draft-only entry: created + draft saved, never published.
        $entries = $this->container()->get(EntryRepository::class);
        $draftOnly = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($draftOnly, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        $this->installSpies();

        $this->tester()->execute(['--type' => 'post']);

        $reindexed = array_map(static fn(array $request): string => $request['entry'], $this->reindexer->requests);
        self::assertContains($published, $reindexed, 'the published entry is re-driven');
        self::assertNotContains($draftOnly, $reindexed, 'a draft-only entry must NEVER be re-driven');
        self::assertNotContains(
            'lemma:entry:' . $draftOnly,
            $this->cache->allInvalidatedTags(),
            'a draft-only entry tag must never be invalidated'
        );
    }

    // ---- --webhooks opt-in ------------------------------------------------------------

    public function testWebhooksFlagReDispatchesWebhooks(): void
    {
        $entry = $this->publishEntry('V1');
        $this->installSpies();

        $exit = $this->tester()->execute(['--entry' => $entry, '--webhooks' => true]);
        self::assertSame(0, $exit);

        self::assertCount(1, $this->webhooks->calls, 'with --webhooks, a webhook must be dispatched');
        $call = $this->webhooks->calls[0];
        self::assertSame('entry.published', $call['event'], 'the re-dispatched event keeps the frozen name');
        self::assertSame($entry, $call['data']['entry']);
        // Identity-only payload — never field values.
        self::assertArrayNotHasKey('fields', $call['data']);
    }

    public function testResyncIsIdempotentWhenRunTwice(): void
    {
        $entry = $this->publishEntry('V1');
        $this->installSpies();

        $this->tester()->execute(['--entry' => $entry]);
        $this->tester()->execute(['--entry' => $entry]);

        // Running twice simply re-drives twice — no error, two reindex requests (the worker
        // re-derives the same document, so this is harmless / idempotent at the effect level).
        self::assertCount(2, $this->reindexer->requests, 'a second resync re-drives again without error');
    }

    // ---- container surgery (the compiled container exposes no setter) -----------------

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
