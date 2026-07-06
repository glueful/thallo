<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockMigrationGate;
use App\Content\Blocks\BlockRestoreProjector;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockBackfillRunner;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Blocks\Migration\UnknownBlockTypeException;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\DTOs\RollbackData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class BlockRestoreProjectionTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $blocks->create(['slug' => 'flip', 'label' => 'Flip', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);
        $blocks->create(['slug' => 'solo', 'label' => 'Solo', 'schema' => [
            ['name' => 'x', 'type' => 'string'],
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

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->connection());
    }

    /** FULLY WIRED publish service: gate + restore projector. */
    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
            null,
            null,
            [],
            $this->container()->get(BlockMigrationGate::class),
            $this->container()->get(BlockRestoreProjector::class),
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

    /** @param list<array<string,mixed>> $ops */
    private function declareFor(string $blockSlug, array $ops): string
    {
        $blockType = (string) (new BlockTypeRepository($this->connection()))->findBySlug($blockSlug)['uuid'];
        return $this->container()->get(BlockMigrationService::class)->migrate($blockType, $ops, 'user00000001');
    }

    /** @param list<array<string,mixed>> $ops */
    private function declare(array $ops): string
    {
        return $this->declareFor('card', $ops);
    }

    private function runner(): BlockBackfillRunner
    {
        return $this->container()->get(BlockBackfillRunner::class);
    }

    private function pinnedVersionUuid(string $entryUuid): string
    {
        return (string) $this->versions()->findPublication($entryUuid, 'en')['version_uuid'];
    }

    /** @return array<string,mixed> */
    private function pinnedFields(string $entryUuid): array
    {
        $version = $this->versions()->findVersionByUuid($this->pinnedVersionUuid($entryUuid));
        return (array) $version['fields'];
    }

    public function testRollbackProjectsTheTimestampSuffixOnly(): void
    {
        // Era 1: field `title`. Publish v1.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'era1']],
        ]], 'restore-me');
        $v1 = $this->pinnedVersionUuid($entry);

        // Migration A: title -> heading. Backfill converges (v2 republished).
        $this->runner()->run($this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]));

        // Migration B: heading -> caption. Converges (v3).
        $this->runner()->run($this->declare([['op' => 'rename', 'from' => 'heading', 'to' => 'caption']]));
        $v3 = $this->pinnedVersionUuid($entry);

        // Rollback to v1: BOTH migrations postdate it -> full suffix applies,
        // and a NEW version materializes (append-and-repin), not a re-pin of v1.
        $result = $this->publishService()->rollback($entry, 'en', $v1, 'user00000001');
        $pinned = $this->pinnedFields($entry);
        self::assertSame('era1', $pinned['body'][0]['data']['caption']);
        self::assertNotSame($v1, $result['version_uuid']);
        self::assertSame($result['version_uuid'], $this->pinnedVersionUuid($entry));

        // Rollback to the migration-B-era version (backfill-created): its
        // created_at postdates B -> NO reprojection, plain re-pin.
        $result = $this->publishService()->rollback($entry, 'en', $v3, 'user00000001');
        self::assertSame($v3, $result['version_uuid']);
        self::assertSame($v3, $this->pinnedVersionUuid($entry));
    }

    public function testRenameReuseChainRestoresCorrectlyPerEra(): void
    {
        // a -> b (migration 1), then b -> a (migration 2). An era-1 version
        // (field `a`) projected through BOTH lands back on `a` — and an era-2
        // version (field `b`) gets ONLY migration 2. Blind full-chain application
        // would corrupt era-2 data; the suffix cannot.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'flip', 'data' => ['a' => 'one']],
        ]], 'flip-entry');
        $v1 = $this->pinnedVersionUuid($entry);
        $this->runner()->run($this->declareFor('flip', [['op' => 'rename', 'from' => 'a', 'to' => 'b']]));
        $v2 = $this->pinnedVersionUuid($entry); // backfill version: {b: one}
        $this->runner()->run($this->declareFor('flip', [['op' => 'rename', 'from' => 'b', 'to' => 'a']]));

        $this->publishService()->rollback($entry, 'en', $v1, null); // suffix = both
        self::assertSame('one', $this->pinnedFields($entry)['body'][0]['data']['a']);

        $this->publishService()->rollback($entry, 'en', $v2, null); // suffix = only #2
        self::assertSame('one', $this->pinnedFields($entry)['body'][0]['data']['a']);
    }

    public function testSameSecondPrecisionAndDeletedTypeBlock(): void
    {
        // Same-second (spec §5 precision pin), made DETERMINISTIC: after creating
        // the rows, pin exact microsecond timestamps directly in the DB so the
        // test proves ordering rather than racing the wall clock.
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'fast']],
        ]], 'fast-entry');
        $v1 = $this->pinnedVersionUuid($entry);
        $m = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        $this->runner()->run($m);
        $vBackfill = $this->pinnedVersionUuid($entry); // backfill-created version

        // One wall-clock second, three distinct microsecond instants:
        $this->connection()->table('entry_versions')->where('uuid', '=', $v1)
            ->update(['created_at' => '2026-07-03 12:00:00.100000']);
        $this->connection()->table('block_type_migrations')->where('uuid', '=', $m)
            ->update(['created_at' => '2026-07-03 12:00:00.200000']);
        $this->connection()->table('entry_versions')->where('uuid', '=', $vBackfill)
            ->update(['created_at' => '2026-07-03 12:00:00.300000']);

        // v1 (.1) predates the migration (.2) -> suffix applies.
        $result = $this->publishService()->rollback($entry, 'en', $v1, null);
        self::assertSame('fast', $this->pinnedFields($entry)['body'][0]['data']['heading']);
        // P1: the reported version is the MATERIALIZED one, not the requested v1.
        self::assertNotSame($v1, $result['version_uuid']);
        self::assertSame($result['version_uuid'], $this->pinnedVersionUuid($entry));

        // The backfill version (.3) postdates the migration (.2) -> plain re-pin,
        // and the reported version IS the requested one.
        $result = $this->publishService()->rollback($entry, 'en', $vBackfill, null);
        self::assertSame($vBackfill, $result['version_uuid']);
        self::assertSame($vBackfill, $this->pinnedVersionUuid($entry));

        // Deleted type: restoring a version containing it is BLOCKED before write.
        $ghostEntry = $this->createPublished(['title' => 'G', 'body' => [
            ['id' => 'g', 'type' => 'solo', 'data' => ['x' => '1']],
        ]], 'ghost-entry');
        $vG = $this->pinnedVersionUuid($ghostEntry);
        $this->publishService()->unpublish($ghostEntry, 'en');
        // Remove the current usage (the draft still carries the block), then delete.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $ghostEntry)->delete();
        (new BlockTypeRepository($this->connection()))->deleteBySlug('solo');
        try {
            $this->publishService()->rollback($ghostEntry, 'en', $vG, null);
            self::fail('expected UnknownBlockTypeException');
        } catch (UnknownBlockTypeException $e) {
            self::assertSame('solo', $e->slug);
        }
        // Nothing was pinned.
        self::assertNull($this->versions()->findPublication($ghostEntry, 'en'));
    }

    public function testRollbackEndpointReportsTheMaterializedVersion(): void
    {
        $entry = $this->createPublished(['title' => 'E', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'era1']],
        ]], 'http-restore');
        $v1 = $this->pinnedVersionUuid($entry);
        $this->runner()->run($this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]));

        $dto = (new RequestDataHydrator())->hydrate(RollbackData::class, ['version_uuid' => $v1], [], []);
        $resp = $this->container()->get(PublicationController::class)
            ->rollback($dto, Request::create('/'), $entry, 'en');
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        // The response names the MATERIALIZED version, not the requested v1.
        self::assertNotSame($v1, $body['data']['version_uuid']);
        self::assertSame($body['data']['version_uuid'], $this->pinnedVersionUuid($entry));
        self::assertIsInt($body['data']['version']);
    }
}
