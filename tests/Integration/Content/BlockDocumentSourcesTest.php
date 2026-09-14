<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationService;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * One registry of block-bearing document sources (visual builder plan A4.4): a rename declared
 * on a block type reaches the current draft, every retained version — not only the published
 * one — and the regions, each persisted only while the document is still what was read.
 */
final class BlockDocumentSourcesTest extends AppTestCase
{
    private string $type = '';

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        if ($blocks->findBySlug('card') === null) {
            $blocks->create([
                'slug' => 'card',
                'label' => 'Card',
                'schema' => [['name' => 'title', 'type' => 'string']],
            ]);
        }
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    private function entries(): EntryRepository
    {
        $types = new ContentTypeRepository($this->connection());
        return new EntryRepository($this->connection(), $this->appContext(), $types);
    }

    private function publish(string $uuid): void
    {
        (new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user1');
    }

    private function card(string $id, string $title): array
    {
        return ['id' => $id, 'type' => 'card', 'data' => ['title' => $title]];
    }

    public function testTheRegistryEnumeratesDraftsEveryVersionAndRegions(): void
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user1');
        $entries->saveDraft($uuid, 'en', ['title' => 'v1', 'body' => [$this->card('c1', 'one')]], 1, 0, 'user1');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'sources-page');
        $this->publish($uuid); // version 1
        $entries->saveDraft($uuid, 'en', ['title' => 'v2', 'body' => [$this->card('c1', 'two')]], 1, 1, 'user1');
        $this->publish($uuid); // version 2 (current); version 1 retained
        $entries->saveDraft($uuid, 'en', ['title' => 'v3', 'body' => [$this->card('c1', 'three')]], 1, 2, 'user1');
        (new RegionRepository($this->connection()))->save('header', [$this->card('r1', 'region')], [], 'user1');

        $seen = [];
        $this->container()->get(BlockDocumentSources::class)->each(
            function (BlockDocumentSource $source, DocumentRef $ref) use (&$seen, $uuid): void {
                $mine = match ($ref->sourceType) {
                    'region' => $ref->sourceId === 'header',
                    'entry_draft', 'entry_published' => $ref->sourceId === $uuid,
                    default => ($ref->meta['entry_uuid'] ?? null) === $uuid,
                };
                if ($mine) {
                    $first = $ref->fields['body'][0] ?? $ref->fields['blocks'][0];
                    $seen[] = $source->id() . ':' . $first['data']['title'];
                }
            },
        );
        sort($seen);
        self::assertSame(
            ['entry_draft:three', 'entry_published:two', 'entry_version:one', 'entry_version:two', 'region:region'],
            $seen,
        );
    }

    public function testARenameBackfillReachesARegionAndRepinsThePublishedVersion(): void
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user1');
        $entries->saveDraft($uuid, 'en', ['title' => 'v1', 'body' => [$this->card('c1', 'old')]], 1, 0, 'user1');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'rename-page');
        $this->publish($uuid);
        $entries->saveDraft($uuid, 'en', ['title' => 'v2', 'body' => [$this->card('c1', 'current')]], 1, 1, 'user1');
        $this->publish($uuid);
        (new RegionRepository($this->connection()))->save('footer', [$this->card('r1', 'foot')], [], 'user1');

        $migration = $this->container()->get(BlockMigrationService::class)->migrate(
            (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'],
            [['op' => 'rename', 'from' => 'title', 'to' => 'heading']],
            'user1',
        );
        $result = $this->container()->get(BlockBackfillRunner::class)->run($migration);
        $report = json_encode($this->migrationRow($migration)['failure_report']);
        self::assertSame(0, $result['failed'], (string) $report);

        // Append-and-repin: a third version carries the rename and is the publication; the older
        // versions keep their era (the restore projection replays migrations on rollback).
        $repo = new VersionRepository($this->connection());
        $versions = $repo->versionsFor($uuid, 'en');
        self::assertCount(3, $versions);
        $pinned = $repo->findVersionByUuid((string) $repo->findPublication($uuid, 'en')['version_uuid']);
        self::assertSame(3, $pinned['version']);
        self::assertSame('current', $pinned['fields']['body'][0]['data']['heading']);
        $first = json_decode((string) $versions[2]['fields'], true);
        self::assertSame('old', $first['body'][0]['data']['title'], 'an older version is left to its era');
        $footer = (new RegionRepository($this->connection()))->find('footer');
        self::assertSame('foot', $footer['blocks'][0]['data']['heading']);
        self::assertSame('completed', $this->migrationRow($migration)['status']);
    }

    public function testPersistIsConditionedOnTheRevisionRead(): void
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user1');
        $entries->saveDraft($uuid, 'en', ['title' => 'D', 'body' => [$this->card('c', 'x')]], 1, 0, 'user1');
        $drafts = $this->container()->get(EntryDraftsSource::class);
        $stale = null;
        $drafts->each(function (DocumentRef $ref) use (&$stale, $uuid): void {
            if ($ref->sourceId === $uuid) {
                $stale = $ref;
            }
        });
        self::assertNotNull($stale);
        // The editor saves in between: the read revision is behind.
        $edited = ['title' => 'D-edited', 'body' => [$this->card('c', 'edited')]];
        $entries->saveDraft($uuid, 'en', $edited, 1, (int) $stale->revision, 'user1');
        self::assertFalse($drafts->persist($stale, ['title' => 'D', 'body' => [$this->card('c', 'migrated')]]));
        $draft = $entries->findDraft($uuid, 'en');
        self::assertSame('edited', $draft['fields']['body'][0]['data']['title'], 'never clobbered');

        (new RegionRepository($this->connection()))->save('header', [$this->card('r', 'a')], [], null);
        $regions = $this->container()->get(RegionsSource::class);
        $read = null;
        $regions->each(function (DocumentRef $ref) use (&$read): void {
            if ($ref->sourceId === 'header') {
                $read = $ref;
            }
        });
        (new RegionRepository($this->connection()))->save('header', [$this->card('r', 'b')], [], null);
        self::assertFalse($regions->persist($read, ['blocks' => [$this->card('r', 'z')]]));
        $header = (new RegionRepository($this->connection()))->find('header');
        self::assertSame('b', $header['blocks'][0]['data']['title']);

        $versions = $this->container()->get(EntryVersionsSource::class);
        self::assertSame('entry_version', $versions->id());
    }

    /**
     * A region stamped by one conversion stage persists again under the next: the revision read
     * and the revision checked at write are the same fingerprint, stamp included.
     */
    public function testAStampedRegionPersistsAgain(): void
    {
        (new RegionRepository($this->connection()))->save('footer', [$this->card('r', 'a')], [], null);
        $regions = $this->container()->get(RegionsSource::class);
        $readAt = function () use ($regions): DocumentRef {
            $found = null;
            $regions->each(function (DocumentRef $ref) use (&$found): void {
                if ($ref->sourceId === 'footer') {
                    $found = $ref;
                }
            });
            self::assertNotNull($found);
            return $found;
        };
        $stamped = ['blocks' => [$this->card('r', 'one')], '_schema' => ['settings' => 1, 'conversions' => ['one']]];
        self::assertTrue($regions->persist($readAt(), $stamped), 'first stage');
        $again = $readAt();
        self::assertSame(['settings' => 1, 'conversions' => ['one']], $again->fields['_schema']);
        $twice = [
            'blocks' => [$this->card('r', 'two')],
            '_schema' => ['settings' => 1, 'conversions' => ['one', 'two']],
        ];
        self::assertTrue($regions->persist($again, $twice), 'the next stage persists over the stamped row');
        $footer = (new RegionRepository($this->connection()))->find('footer');
        self::assertSame('two', $footer['blocks'][0]['data']['title']);
        self::assertSame(['one', 'two'], $readAt()->fields['_schema']['conversions']);
    }

    public function testACasMissCountsAsARedrivableFailure(): void
    {
        $uuid = $this->entries()->createEntry($this->type, 'en', 1, 'user1');
        $this->entries()->saveDraft($uuid, 'en', ['title' => 'D', 'body' => [$this->card('c', 'x')]], 1, 0, 'user1');
        $migration = $this->container()->get(BlockMigrationService::class)->migrate(
            (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'],
            [['op' => 'rename', 'from' => 'title', 'to' => 'heading']],
            'user1',
        );
        // A source whose persist always loses the race: the runner reports, never clobbers.
        $drafts = $this->container()->get(EntryDraftsSource::class);
        $losing = new class ($drafts) implements BlockDocumentSource {
            public function __construct(private readonly EntryDraftsSource $inner)
            {
            }
            public function id(): string
            {
                return 'entry_draft';
            }
            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }
            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                return false;
            }
        };
        $runner = $this->container()->get(BlockBackfillRunner::class)
            ->withSources(new BlockDocumentSources($losing));
        $result = $runner->run($migration);
        self::assertSame(1, $result['failed']);
        $draft = $this->entries()->findDraft($uuid, 'en');
        self::assertSame('x', $draft['fields']['body'][0]['data']['title']);
        $report = (string) json_encode($this->migrationRow($migration)['failure_report']);
        self::assertStringContainsString('concurrently', $report);
    }

    /** @return array<string,mixed> */
    private function migrationRow(string $uuid): array
    {
        $blocks = new BlockTypeRepository($this->connection());
        return (array) (new BlockMigrationRepository($this->connection(), $blocks))->find($uuid);
    }
}
