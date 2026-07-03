<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\BlockUsageScanner;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Http\Controllers\BlockTypeController;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class BlockUsageAndDeleteTest extends LemmaTestCase
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

    /** @param array<string,mixed> $fields */
    private function createPublished(array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
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

    /** @param array<string,mixed> $fields */
    private function republish(string $entryUuid, array $fields): void
    {
        $this->entries()->saveDraft($entryUuid, 'en', $fields, 1, 1, 'user00000001');
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
        ))->publish($entryUuid, 'en', 'user00000001');
    }

    /** @param list<string> $blockTypes */
    private function createContentTypeWithAllowlist(string $slug, array $blockTypes): void
    {
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks', 'block_types' => $blockTypes],
            ],
        ]);
    }

    private function scanner(): BlockUsageScanner
    {
        return $this->container()->get(BlockUsageScanner::class);
    }

    private function deleteViaController(string $slug): Response
    {
        return $this->container()->get(BlockTypeController::class)
            ->destroy(Request::create('/', 'DELETE'), $slug);
    }

    public function testUsageCountsCurrentAndArchivedNestedButNotHistoricalAndReportsAllowlists(): void
    {
        // Content type with a block_types allowlist naming `card` (report-only).
        $this->createContentTypeWithAllowlist('landing', ['card']);

        // Draft-only usage + archived published usage (nested).
        $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        $archived = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'c2', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]], 'arch');
        // Remove the archived entry's DRAFT so only its publication counts, then archive.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $archived)->delete();
        $this->connection()->table('entries')->where('uuid', '=', $archived)
            ->update(['status' => 'archived']);

        // HISTORICAL-only usage: publish with card, then republish WITHOUT it —
        // the old version still has it; must NOT count (draft + publication are
        // both card-free after the republish).
        $hist = $this->createPublished(['title' => 'H', 'body' => [
            ['id' => 'c3', 'type' => 'card', 'data' => ['title' => 'old']],
        ]], 'hist');
        $this->republish($hist, ['title' => 'H', 'body' => []]);

        $usage = $this->scanner()->usage('card');
        self::assertSame(2, $usage['total']); // draft-only D + archived publication
        self::assertContains('landing', $usage['allowlists']);

        // Allowlist-only usage does not gate: `solo` allowlisted, never instantiated.
        $this->createContentTypeWithAllowlist('promo', ['solo']);
        self::assertSame(0, $this->scanner()->usage('solo')['total']);
        self::assertContains('promo', $this->scanner()->usage('solo')['allowlists']);
    }

    public function testDeleteGatesServerSideAndDeletesAtZero(): void
    {
        $entry = $this->draftOnly(['title' => 'D', 'body' => [
            ['id' => 'c', 'type' => 'card', 'data' => ['title' => 'x']],
        ]]);
        self::assertSame(409, $this->deleteViaController('card')->getStatusCode());

        // Remove the usage; delete succeeds; registry row is GONE.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->delete();
        self::assertSame(200, $this->deleteViaController('card')->getStatusCode());
        self::assertNull((new BlockTypeRepository($this->connection()))->findBySlug('card'));

        // Unknown slug -> 404.
        self::assertSame(404, $this->deleteViaController('card')->getStatusCode());
    }

    public function testDeleteBlockedDuringActiveMigration(): void
    {
        $blockType = (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'];
        $this->container()->get(BlockMigrationService::class)
            ->migrate($blockType, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
        self::assertSame(409, $this->deleteViaController('card')->getStatusCode());
    }
}
