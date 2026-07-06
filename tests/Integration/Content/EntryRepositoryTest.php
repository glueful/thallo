<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Tests\Support\AppTestCase;

final class EntryRepositoryTest extends AppTestCase
{
    private function repo(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    public function testCreateEntryStartsAnEmptyDraft(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $draft = $this->repo()->findDraft($entry, 'en');
        self::assertSame(0, $draft['lock_version']);
        self::assertSame([], $draft['fields']);
    }

    public function testSaveDraftIncrementsLockVersion(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001');
        self::assertSame(1, $this->repo()->findDraft($entry, 'en')['lock_version']);
    }

    public function testStaleSaveThrows409(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001'); // now lock_version=1
        $this->expectException(OptimisticLockException::class);
        $this->repo()->saveDraft($entry, 'en', ['title' => 'B'], 1, 0, 'user00000001'); // stale 0
    }

    public function testCreateLocaleDraftSeedsNonLocalizedAndOmitsLocalized(): void
    {
        [$typeUuid, $schema] = $this->localizedType();
        $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

        $fr = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', false, $schema);

        self::assertSame('fr', $fr['locale']);
        self::assertSame(['price' => 42], $fr['fields'], 'price (shared) copied, title (localized) omitted');
        self::assertArrayNotHasKey('title', $fr['fields']);
        self::assertEquals(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'en')['fields']);
    }

    public function testCreateLocaleDraftWithoutSourceIsUnchangedEmptyDraft(): void
    {
        [$typeUuid, $schema] = $this->localizedType();
        $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');

        $fr = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', null, false, $schema);

        self::assertSame([], $fr['fields'], 'no source locale -> empty draft, no seeding');
        self::assertSame(0, $fr['lock_version']);
    }

    public function testOverwriteReseedDropsTargetLocalizedAndResetsShared(): void
    {
        [$typeUuid, $schema] = $this->localizedType();
        $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

        $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', false, $schema);
        $frLock = (int) $this->repo()->findDraft($entry, 'fr')['lock_version'];
        $this->repo()->saveDraft($entry, 'fr', ['title' => 'Bonjour', 'price' => 99], 1, $frLock, 'user00000001');

        $reseeded = $this->repo()->createLocaleDraft($entry, 'fr', 1, 'user00000001', 'en', true, $schema);

        self::assertArrayNotHasKey('title', $reseeded['fields'], 'overwrite re-seed drops target localized value');
        self::assertSame(42, $reseeded['fields']['price'], 'shared price reset to source value');
    }

    public function testFlagChangeIsProspectiveOnlyForExistingDrafts(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = $types->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'price', 'type' => 'number'],
            ],
        ]);
        $entry = $this->repo()->createEntry($typeUuid, 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'English', 'price' => 42], 1, 0, 'user00000001');

        $v1 = (int) $types->findByUuid($typeUuid)['schema_version'];
        $sharedSchema = $types->schemaFor($typeUuid);
        $fr = $this->repo()->createLocaleDraft($entry, 'fr', $v1, 'user00000001', 'en', false, $sharedSchema);

        self::assertEquals(['title' => 'English', 'price' => 42], $fr['fields']);
        self::assertSame($v1, (int) $fr['schema_version']);

        $types->updateSchema($typeUuid, [
            ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
            ['name' => 'price', 'type' => 'number'],
        ]);

        self::assertEquals(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'en')['fields']);
        self::assertEquals(['title' => 'English', 'price' => 42], $this->repo()->findDraft($entry, 'fr')['fields']);

        $v2 = (int) $types->findByUuid($typeUuid)['schema_version'];
        self::assertGreaterThan($v1, $v2, 'updateSchema bumped the content type schema version');
        $newSchema = $types->schemaFor($typeUuid);
        $de = $this->repo()->createLocaleDraft($entry, 'de', $v2, 'user00000001', 'en', false, $newSchema);

        self::assertSame(['price' => 42], $de['fields'], 'next create reflects the prospective flag change');
        self::assertSame($v2, (int) $de['schema_version']);
    }

    /**
     * @return array{0:string,1:\App\Content\Schema\ContentTypeSchema}
     */
    private function localizedType(): array
    {
        $types = new ContentTypeRepository($this->connection());
        $uuid = $types->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
                ['name' => 'price', 'type' => 'number'],
            ],
        ]);

        return [$uuid, $types->schemaFor($uuid)];
    }
}
