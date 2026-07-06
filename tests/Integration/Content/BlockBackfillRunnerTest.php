<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockBackfillRunner;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;

final class BlockBackfillRunnerTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $blocks->create(['slug' => 'nest', 'label' => 'Nest', 'schema' => [
            ['name' => 'inner', 'type' => 'blocks'],
        ]]);
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
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
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
        );
    }

    /** @param array<string,mixed> $fields */
    private function createPublished(array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        $this->publishService()->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /** @param array<string,mixed> $fields */
    private function draftOnly(array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    /** @param list<array<string,mixed>> $ops */
    private function declare(array $ops): string
    {
        return $this->container()->get(BlockMigrationService::class)
            ->migrate($this->cardTypeUuid(), $ops, 'user00000001');
    }

    private function cardTypeUuid(): string
    {
        return (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'];
    }

    private function runner(): BlockBackfillRunner
    {
        return $this->container()->get(BlockBackfillRunner::class);
    }

    private function migrations(): BlockMigrationRepository
    {
        return new BlockMigrationRepository(
            $this->connection(),
            new BlockTypeRepository($this->connection()),
        );
    }

    /** @return array<string,mixed> */
    private function migrationRow(string $uuid): array
    {
        return (array) $this->migrations()->find($uuid);
    }

    /** @return array<string,mixed> the pinned publication's decoded fields */
    private function pinnedFields(string $entryUuid): array
    {
        $versions = new VersionRepository($this->connection());
        $pub = $versions->findPublication($entryUuid, 'en');
        $version = $versions->findVersionByUuid((string) $pub['version_uuid']);
        return (array) $version['fields'];
    }

    private function opSetFor(string $migrationUuid): MigrationOpSet
    {
        return MigrationOpSet::fromArray($this->migrationRow($migrationUuid)['ops']);
    }

    private function pageSchema(): ContentTypeSchema
    {
        return (new ContentTypeRepository($this->connection()))->schemaFor($this->type);
    }

    public function testBackfillRewritesDraftsAndPublicationsIncludingArchivedAndNested(): void
    {
        // Published source with nested card; then ARCHIVE it (spec §4: archived
        // entries are rewritten — not-deleted predicate, not active-only).
        $archived = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]], 'archived-one');
        $this->connection()->table('entries')->where('uuid', '=', $archived)
            ->update(['status' => 'archived']);

        // A draft-only entry with a top-level card.
        $draft = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c2', 'type' => 'card', 'data' => ['title' => 'drafty']],
        ]]);

        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        $result = $this->runner()->run($migration);
        self::assertSame(0, $result['failed']);

        // Draft rewritten in place.
        $draftRow = $this->entries()->findDraft($draft, 'en');
        self::assertSame('drafty', $draftRow['fields']['body'][0]['data']['heading']);

        // Archived entry's publication: NEW version + repin, nested rewritten.
        $pinned = $this->pinnedFields($archived);
        self::assertSame('deep', $pinned['body'][0]['data']['inner'][0]['data']['heading']);

        // Status completed; accounting adds up.
        $row = $this->migrationRow($migration);
        self::assertSame('completed', $row['status']);
        self::assertSame((int) $row['work_items_total'], (int) $row['work_items_done']);
        self::assertNotNull($row['completed_at']);
    }

    public function testStaleLockCasMissCountsAsRedrivableFailureAndNeverClobbers(): void
    {
        // BackfillRunnerTest precedent: drive processDraft with a STALE work item
        // via reflection — the CAS must miss, the editor's newer content must
        // survive byte-identical, and the failure must be recorded.
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);

        $staleItem = [
            'entry_uuid' => $entry,
            'locale' => 'en',
            'fields' => json_encode(['title' => 'D', 'body' => [
                ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
            ]], JSON_THROW_ON_ERROR),
            'lock_version' => 0, // stale: the editor saved since (below)
        ];
        // "Editor save" after the work list was read: newer content + bumped lock.
        $editorFields = ['title' => 'D-edited', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'edited']],
        ]];
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->update([
            'fields' => json_encode($editorFields, JSON_THROW_ON_ERROR),
            'lock_version' => 7,
        ]);

        $runner = $this->runner();
        $m = new \ReflectionMethod($runner, 'processDraft');
        $m->invoke($runner, $migration, 'card', $this->opSetFor($migration), $this->pageSchema(), $staleItem);

        // Editor content survives (assertEquals: JSON round-trips reorder keys;
        // VALUE equality is the invariant); failure recorded, re-drivable.
        $draft = $this->entries()->findDraft($entry, 'en');
        self::assertEquals($editorFields, $draft['fields']);
        self::assertSame(7, (int) $draft['lock_version']);
        self::assertSame(1, (int) $this->migrationRow($migration)['work_items_failed']);
    }

    public function testOpCollisionCountsAsFailureAndMarksMigrationFailed(): void
    {
        // An instance that ALREADY has the rename target makes RenameField::apply
        // throw MigrationCollisionException -> recorded failure; the end-of-run
        // recount marks the migration failed (write gate stays closed).
        $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x', 'heading' => 'y']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);

        $result = $this->runner()->run($migration);
        self::assertSame(1, $result['failed']);
        self::assertSame('failed', (string) $this->migrationRow($migration)['status']);
    }
}
