<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;

final class PublishServiceTest extends AppTestCase
{
    private string $type;
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->entries();
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function service(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    public function testPublishWritesVersion1AndPins(): void
    {
        $versionUuid = $this->service()->publish($this->entry, 'en', 'user00000001');
        $pub = (new VersionRepository($this->connection()))->findPublication($this->entry, 'en');
        self::assertSame($versionUuid, $pub['version_uuid']);
        $v = (new VersionRepository($this->connection()))->findVersionByUuid($versionUuid);
        self::assertSame(1, $v['version']);
        self::assertSame('V1', $v['fields']['title']);
    }

    public function testSecondPublishAppendsVersion2(): void
    {
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->entries()
            ->saveDraft($this->entry, 'en', ['title' => 'V2'], 1, 1, 'user00000001');
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $repo = new VersionRepository($this->connection());
        self::assertSame(
            2,
            $repo->findVersionByUuid($repo->findPublication($this->entry, 'en')['version_uuid'])['version']
        );
        self::assertCount(2, $repo->versionsFor($this->entry, 'en'));
    }

    public function testReserveNextVersionNumberUsesTransactionScopedLock(): void
    {
        $versions = new VersionRepository($this->connection());

        $locked = db($this->appContext())->transaction(function () use ($versions): array {
            $next = $versions->reserveNextVersionNumber($this->entry, 'en');
            $locks = $this->connection()->getPDO()->query(
                "SELECT mode FROM pg_locks WHERE locktype = 'advisory' AND granted = true"
            )->fetchAll(\PDO::FETCH_ASSOC);

            return [$next, $locks];
        });

        self::assertSame(1, $locked[0]);
        self::assertNotSame([], $locked[1], 'version allocation must hold an advisory transaction lock');

        $locksAfterCommit = $this->connection()->getPDO()->query(
            "SELECT mode FROM pg_locks WHERE locktype = 'advisory' AND granted = true"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([], $locksAfterCommit, 'the version allocation lock must release on commit');
    }

    public function testPublishRejectsInvalidDraft(): void
    {
        // setUp already saved the draft once, so lock_version is now 1 (not 0).
        // Save an empty payload at the CURRENT lock version so the save succeeds; this
        // clears the required `title`. The assertion under test is that publishing an
        // invalid draft throws ValidationException and writes NO version row — not that
        // a stale-lock error fires. Read the live lock_version to avoid that masquerade.
        $entries = $this->entries();
        $currentLock = (int) $entries->findDraft($this->entry, 'en')['lock_version'];
        $entries->saveDraft($this->entry, 'en', [], 1, $currentLock, 'user00000001');

        $versions = new VersionRepository($this->connection());
        self::assertSame([], $versions->versionsFor($this->entry, 'en'), 'no versions before publish');

        try {
            $this->service()->publish($this->entry, 'en', 'user00000001');
            self::fail('expected ValidationException for invalid draft');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors());
        }

        // Validation happens before the transaction, so an invalid draft writes no version.
        self::assertSame([], $versions->versionsFor($this->entry, 'en'), 'invalid draft must write no version');
        self::assertNull($versions->findPublication($this->entry, 'en'));
    }

    public function testUnpublishRemovesPin(): void
    {
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->service()->unpublish($this->entry, 'en');
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testRollbackRepinsOlderVersion(): void
    {
        $v1 = $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->entries()
            ->saveDraft($this->entry, 'en', ['title' => 'V2'], 1, 1, 'user00000001');
        $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->service()->rollback($this->entry, 'en', $v1, 'user00000001');
        self::assertSame($v1, (new VersionRepository($this->connection()))
            ->findPublication($this->entry, 'en')['version_uuid']);
    }

    public function testPublishRejectsSoftDeletedEntry(): void
    {
        $this->entries()->softDelete($this->entry);

        $this->expectException(\RuntimeException::class);
        $this->service()->publish($this->entry, 'en', 'user00000001');
    }

    public function testRollbackRejectsSoftDeletedEntry(): void
    {
        $v1 = $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->entries()->saveDraft($this->entry, 'en', ['title' => 'V2'], 1, 1, 'user00000001');
        $v2 = $this->service()->publish($this->entry, 'en', 'user00000001');
        $this->entries()->softDelete($this->entry);

        try {
            $this->service()->rollback($this->entry, 'en', $v1, 'user00000001');
            self::fail('expected rollback on a soft-deleted entry to throw');
        } catch (\RuntimeException) {
            // expected
        }

        // The pin was NOT rewound onto the deleted entry — it still points at V2.
        self::assertSame(
            $v2,
            (new VersionRepository($this->connection()))->findPublication($this->entry, 'en')['version_uuid'],
        );
    }

    public function testReferenceProjectionTracksPublishedSnapshotOnly(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'linked',
            'name' => 'Linked',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'related', 'type' => 'reference'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $targetA = $entries->createEntry($type, 'en', 1, 'user00000001');
        $targetB = $entries->createEntry($type, 'en', 1, 'user00000001');
        $source = $entries->createEntry($type, 'en', 1, 'user00000001');

        $entries->saveDraft($source, 'en', ['title' => 'Source', 'related' => $targetA], 1, 0, 'user00000001');
        $references = new ReferenceProjectionRepository($this->connection());
        self::assertSame([], $references->referencesTo($targetA), 'draft saves must not affect published references');

        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            $references,
        ))->publish($source, 'en', 'user00000001');
        self::assertNotSame([], $references->referencesTo($targetA), 'publish indexes the published snapshot');

        $draft = $entries->findDraft($source, 'en');
        self::assertIsArray($draft);
        $entries->saveDraft(
            $source,
            'en',
            ['title' => 'Source', 'related' => $targetB],
            1,
            (int) $draft['lock_version'],
            'user00000001'
        );

        self::assertNotSame(
            [],
            $references->referencesTo($targetA),
            'unpublished draft edits keep the old published index'
        );
        self::assertSame([], $references->referencesTo($targetB), 'new draft references are not indexed until publish');
    }
}
