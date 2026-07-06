<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockMigrationGate;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationInProgressException;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Http\Response;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class BlockMigrationGateTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'card',
            'label' => 'Card',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
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
        $blockType = (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'];
        return $this->container()->get(BlockMigrationService::class)->migrate($blockType, $ops, null);
    }

    private function migrations(): BlockMigrationRepository
    {
        return new BlockMigrationRepository(
            $this->connection(),
            new BlockTypeRepository($this->connection()),
        );
    }

    /** GATED publish service — the gate is what's under test. */
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
            null,
            null,
            [],
            $this->container()->get(BlockMigrationGate::class),
        );
    }

    /** @param array<string,mixed> $fields */
    private function saveDraftViaController(string $entryUuid, array $fields): Response
    {
        $draft = $this->entries()->findDraft($entryUuid, 'en');
        $body = ['fields' => $fields, 'lock_version' => (int) ($draft['lock_version'] ?? 0)];
        $dto = (new RequestDataHydrator())->hydrate(SaveDraftData::class, $body, [], []);
        return $this->container()->get(EntryController::class)
            ->saveDraft($dto, Request::create('/'), $entryUuid, 'en');
    }

    public function testSaveAndPublishAreGatedWhileMigrationActiveIncludingFailed(): void
    {
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $migration = $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        // Migration row is 'running' (backfill not run in this test).

        // SAVE 409s.
        $resp = $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x2']],
        ]]);
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('BLOCK_MIGRATION_IN_PROGRESS', (string) $resp->getContent());

        // PUBLISH 409s (stored draft contains the slug).
        try {
            $this->publishService()->publish($entry, 'en', 'user00000001');
            self::fail('expected gate');
        } catch (BlockMigrationInProgressException) {
            $this->addToAssertionCount(1);
        }

        // FAILED keeps the gate closed.
        $this->migrations()->finish($migration, 'failed');
        self::assertSame(409, $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x2']],
        ]])->getStatusCode());

        // COMPLETED opens it (payload uses the NEW schema key).
        $this->migrations()->finish($migration, 'completed');
        self::assertSame(200, $this->saveDraftViaController($entry, ['title' => 'D2', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['heading' => 'x2']],
        ]])->getStatusCode());
    }

    public function testEntriesWithoutTheMigratingTypeSaveNormally(): void
    {
        $entry = $this->draftOnly(['title' => 'Plain', 'body' => []]);
        $this->declare([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]);
        self::assertSame(200, $this->saveDraftViaController($entry, [
            'title' => 'Plain2', 'body' => [],
        ])->getStatusCode());
    }
}
