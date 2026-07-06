<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Console\ResyncCommand;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Schema\Migration\SchemaProjector;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The published-reference projection (term-archives/facets spec §1): write-side rebuild
 * semantics (incl. schema-migration projection for rolled-back versions), listener
 * wiring through real events, and the lemma:resync re-drive.
 */
final class PublishedReferenceProjectionTest extends AppTestCase
{
    private const CAT_TYPE_UUID = 'cattypeproj0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID,
            'slug' => 'category',
            'name' => 'Category',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true]],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple' => true,
                    'filterable' => true,
                ],
                ['name' => 'gallery', 'type' => 'asset', 'multiple' => true],
            ],
        ]);
    }

    private function projection(): PublishedReferenceRepository
    {
        return new PublishedReferenceRepository(
            $this->connection(),
            new ContentTypeRepository($this->connection()),
            $this->container()->get(SchemaProjector::class),
        );
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->connection()->table('published_entry_references')
            ->select(['source_entry_uuid', 'source_content_type_uuid', 'field', 'target_entry_uuid', 'locale'])
            ->orderBy(['id' => 'ASC'])
            ->get();
    }

    /** Seed a published post row directly (spine tables), like ReferenceDeliveryFilterTest. */
    private function seedPublishedPost(
        string $entryUuid,
        string $versionUuid,
        array $rawFields,
        string $locale = 'en',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid,
            'content_type_uuid' => $this->postType,
            'status' => 'active',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version' => 1,
            'fields' => json_encode($rawFields, JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    public function testProjectFromPublishedWritesReferenceRowsOnly(): void
    {
        $this->seedPublishedPost('projpost0001', 'projpostv001', [
            'title' => 'P',
            'category' => ['catterm00001', 'catterm00002'],
            'gallery' => ['blob00000001'],  // asset: never projected
        ]);

        $this->projection()->projectFromPublished('projpost0001', $this->postType, 'en');

        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame('category', $rows[0]['field']);
        self::assertSame($this->postType, $rows[0]['source_content_type_uuid']);
        self::assertSame(
            ['catterm00001', 'catterm00002'],
            array_column($rows, 'target_entry_uuid'),
        );
    }

    public function testScalarStoredValueProjectsAsOneElement(): void
    {
        $this->seedPublishedPost('projscalar01', 'projscalarv1', [
            'title' => 'S',
            'category' => 'catterm00001', // pre-flip scalar
        ]);
        $this->projection()->projectFromPublished('projscalar01', $this->postType, 'en');
        self::assertCount(1, $this->rows());
    }

    public function testReprojectIsIdempotentAndDropsRemovedTargets(): void
    {
        $this->seedPublishedPost('projidem0001', 'projidemv001', [
            'title' => 'I', 'category' => ['catterm00001'],
        ]);
        $p = $this->projection();
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        self::assertCount(1, $this->rows()); // no duplicates

        // Simulate a republish with a different target set: version fields changed.
        $this->connection()->table('entry_versions')
            ->where('uuid', '=', 'projidemv001')
            ->update([
                'fields' => json_encode(['title' => 'I', 'category' => ['catterm00002']], JSON_THROW_ON_ERROR),
            ]);
        $p->projectFromPublished('projidem0001', $this->postType, 'en');
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('catterm00002', $rows[0]['target_entry_uuid']);
    }

    public function testUnpublishedEntryProjectsNothing(): void
    {
        // No publication row at all → projectFromPublished is a no-op (clears any stale rows).
        $this->projection()->projectFromPublished('projnone0001', $this->postType, 'en');
        self::assertSame([], $this->rows());
    }

    public function testOldSchemaVersionFieldsProjectThroughMigrationChain(): void
    {
        // Rollback safety (review P1): a re-pinned older version stores its refs under a
        // field name the CURRENT schema has since renamed. The projection must apply the
        // schema-migration chain before scanning — exactly like delivery shaping does.
        // Old schema (v1) had `topics`; current (v2) renamed it to `category`.
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)
            ->update(['schema_version' => 2]);
        $this->connection()->table('entry_schema_migrations')->insert([
            'uuid' => 'schmig000001',
            'content_type_uuid' => $this->postType,
            'from_version' => 1,
            'to_version' => 2,
            'ops' => json_encode(
                [['op' => 'rename', 'from' => 'topics', 'to' => 'category']],
                JSON_THROW_ON_ERROR,
            ),
            'status' => 'completed',
            'created_at' => '2026-06-01 00:00:00',
        ]);
        // The pinned version predates the rename: schema_version=1, refs under `topics`.
        $this->seedPublishedPost('projdrift001', 'projdriftv01', [
            'title' => 'Old', 'topics' => ['catterm00001'],
        ]);

        $this->projection()->projectFromPublished('projdrift001', $this->postType, 'en');

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('category', $rows[0]['field']); // the CURRENT field name
        self::assertSame('catterm00001', $rows[0]['target_entry_uuid']);
    }

    public function testClearsAreScopedCorrectly(): void
    {
        $this->seedPublishedPost('projclr00001', 'projclrv0001', ['title' => 'A', 'category' => ['catterm00001']]);
        $this->seedPublishedPost('projclr00002', 'projclrv0002', ['title' => 'B', 'category' => ['catterm00001']]);
        $p = $this->projection();
        $p->projectFromPublished('projclr00001', $this->postType, 'en');
        $p->projectFromPublished('projclr00002', $this->postType, 'en');
        self::assertCount(2, $this->rows());

        $p->clearForEntryLocale('projclr00001', 'fr'); // wrong locale: no effect
        self::assertCount(2, $this->rows());
        $p->clearForEntryLocale('projclr00001', 'en');
        self::assertCount(1, $this->rows());
        $p->clearForTarget('catterm00001'); // hygiene: rows pointing AT the term
        self::assertSame([], $this->rows());
    }

    public function testPublishAndUnpublishEventsMaintainProjectionThroughWiredListeners(): void
    {
        $this->seedPublishedPost('projevt00001', 'projevtv0001', ['title' => 'E', 'category' => ['catterm00001']]);
        $events = $this->container()->get(EventService::class);

        $events->dispatch(new EntryPublished('projevt00001', $this->postType, 'en'));
        self::assertCount(1, $this->rows());

        $events->dispatch(new EntryUnpublished('projevt00001', $this->postType, 'en'));
        self::assertSame([], $this->rows());
    }

    public function testDeleteEventClearsSourceAndTargetRows(): void
    {
        $this->seedPublishedPost('projdelsrc01', 'projdelsrcv1', ['title' => 'D', 'category' => ['projdeltgt01']]);
        $this->projection()->projectFromPublished('projdelsrc01', $this->postType, 'en');
        // A second row where the deleted entry is the TARGET.
        $this->seedPublishedPost('projdeloth01', 'projdelothv1', ['title' => 'O', 'category' => ['projdelsrc01']]);
        $this->projection()->projectFromPublished('projdeloth01', $this->postType, 'en');
        self::assertCount(2, $this->rows());

        $this->container()->get(EventService::class)
            ->dispatch(new EntryDeleted('projdelsrc01', $this->postType, 'en'));

        // Source rows gone AND rows pointing at it gone (hygiene).
        self::assertSame([], $this->rows());
    }

    public function testRollbackRepinRebuildsProjectionFromTheRepinnedVersion(): void
    {
        // Rollback characterization (review P1): PublishService::rollback re-pins an
        // older version and emits EntryPublished — the projection must rebuild from
        // whatever is PINNED, not from the latest version. Simulate the re-pin the way
        // rollback does (publication row swaps its version_uuid), then dispatch the
        // event rollback emits.
        $this->seedPublishedPost('projrbk00001', 'projrbkv0002', ['title' => 'V2', 'category' => ['catterm00002']]);
        // A second version row for the same entry (distinct version number — the helper
        // used 1), referencing a DIFFERENT term. Which row is "older" is irrelevant to
        // the projection; only the publication's pinned version_uuid matters.
        $this->connection()->table('entry_versions')->insert([
            'uuid' => 'projrbkv0001', 'entry_uuid' => 'projrbk00001', 'locale' => 'en', 'version' => 2,
            'fields' => json_encode(['title' => 'V1', 'category' => ['catterm00001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-05-01 00:00:00',
        ]);
        $events = $this->container()->get(EventService::class);
        $events->dispatch(new EntryPublished('projrbk00001', $this->postType, 'en'));
        self::assertSame('catterm00002', $this->rows()[0]['target_entry_uuid']); // v2 pinned

        // The rollback re-pin + the EntryPublished it emits (PublishService.php ~177).
        $this->connection()->table('entry_publications')
            ->where('entry_uuid', '=', 'projrbk00001')->where('locale', '=', 'en')
            ->update(['version_uuid' => 'projrbkv0001']);
        $events->dispatch(new EntryPublished('projrbk00001', $this->postType, 'en'));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('catterm00001', $rows[0]['target_entry_uuid']); // rebuilt from v1
    }

    public function testResyncRedrivesTheProjection(): void
    {
        $this->seedPublishedPost('projsync0001', 'projsyncv001', ['title' => 'R', 'category' => ['catterm00001']]);
        // Simulate the dropped afterCommit: projection is empty despite published content.
        self::assertSame([], $this->rows());

        $tester = new CommandTester(new ResyncCommand($this->container(), $this->appContext()));
        $exit = $tester->execute(['--type' => 'post']);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->rows()); // the projection reconverged
    }
}
