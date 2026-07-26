<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Delivery\DeliveryItemShaper;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\EnginePublishedEntryBlocksReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Content\EntryExistenceReader;

/**
 * Commerce-Slice-2 Fix B: {@see EnginePublishedEntryBlocksReader} — the route-INDEPENDENT,
 * tenant-scoped, published-only entry read {@see \Thallo\Render\EntryBlocksRenderer} composes.
 * Unlike {@see \App\Content\Delivery\EnginePublicRouteResolver::resolveEntry()} (which this
 * reader deliberately does NOT call), an entry with zero `entry_routes` rows still resolves
 * here — that is the entire point of this seam. Every fail-closed reason is exercised
 * independently: missing, soft-deleted, cross-tenant, non-public-delivery type, and
 * draft-only (never published).
 */
final class PublishedEntryBlocksReaderTest extends AppTestCase
{
    private static int $seq = 0;

    private function reader(): EnginePublishedEntryBlocksReader
    {
        return new EnginePublishedEntryBlocksReader(
            $this->container()->get(EntryExistenceReader::class),
            new ContentTypeRepository($this->connection()),
            $this->container()->get(DeliveryRepository::class),
            $this->container()->get(DeliveryItemShaper::class),
        );
    }

    /** A content type with a `body: blocks` field, mirroring the commerce "Product story" starter shape. */
    private function createType(bool $publicDelivery = true): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'pebr_test_' . (++self::$seq),
            'name' => 'PEBR Test',
            'public_delivery' => $publicDelivery,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /**
     * A published entry with NO `entry_routes` row at all — the route-less shape this whole
     * seam exists to serve. `$blocks` becomes the entry's `body` field.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private function seedRouteLessPublishedEntry(string $typeUuid, string $locale, array $blocks): string
    {
        if ($blocks !== []) {
            $this->ensureHeadingBlockTypeSeeded();
        }
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, ['title' => 'PEBR entry', 'body' => $blocks], 1, 0, 'user00000001');
        // Deliberately NO RouteRepository::assign() call — this entry never gets a route.
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, $locale, 'user00000001');

        return $entry;
    }

    /** A draft-only entry (never published) — no `entry_publications` row. */
    private function seedDraftOnlyEntry(string $typeUuid, string $locale): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, $locale, 1, 'user00000001');
        $entries->saveDraft($entry, $locale, ['title' => 'Never published'], 1, 0, 'user00000001');

        return $entry;
    }

    private function ensureHeadingBlockTypeSeeded(): void
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        if ($blockTypes->findBySlug('heading') !== null) {
            return;
        }
        $blockTypes->create([
            'slug' => 'heading',
            'label' => 'Heading',
            'schema' => [['name' => 'text', 'type' => 'string']],
        ]);
    }

    // ------------------------------------------------------------------
    // the positive case: route-less resolves fine
    // ------------------------------------------------------------------

    public function testRouteLessPublishedEntryResolvesWithoutAnyRouteRow(): void
    {
        $typeUuid = $this->createType();
        $entryUuid = $this->seedRouteLessPublishedEntry(
            $typeUuid,
            'en',
            [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'ROUTELESS-BLOCK-MARKER']]],
        );
        self::assertSame(
            [],
            $this->connection()->table('entry_routes')->where('entry_uuid', '=', $entryUuid)->get(),
            'precondition: this entry must carry zero route rows',
        );

        $result = $this->reader()->findPublishedBlocks($entryUuid, '', 'en');

        self::assertNotNull($result);
        self::assertSame($entryUuid, $result['entry_uuid']);
        self::assertSame(
            'ROUTELESS-BLOCK-MARKER',
            $result['fields']['body'][0]['data']['text'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // fail-closed: missing / deleted / cross-tenant / non-public / draft-only
    // ------------------------------------------------------------------

    public function testMissingEntryFailsClosed(): void
    {
        self::assertNull($this->reader()->findPublishedBlocks('doesnotexist', '', 'en'));
    }

    public function testSoftDeletedEntryFailsClosed(): void
    {
        $typeUuid = $this->createType();
        $entryUuid = $this->seedRouteLessPublishedEntry($typeUuid, 'en', []);
        $this->connection()->table('entries')->where('uuid', '=', $entryUuid)->update(['status' => 'deleted']);

        self::assertNull($this->reader()->findPublishedBlocks($entryUuid, '', 'en'));
    }

    public function testDraftOnlyEntryNeverPublishedFailsClosed(): void
    {
        $typeUuid = $this->createType();
        $entryUuid = $this->seedDraftOnlyEntry($typeUuid, 'en');

        self::assertNull($this->reader()->findPublishedBlocks($entryUuid, '', 'en'));
    }

    public function testNonPubliclyDeliverableTypeFailsClosed(): void
    {
        $typeUuid = $this->createType(publicDelivery: false);
        $entryUuid = $this->seedRouteLessPublishedEntry($typeUuid, 'en', []);

        self::assertNull($this->reader()->findPublishedBlocks($entryUuid, '', 'en'));
    }

    public function testWrongLocaleFailsClosed(): void
    {
        $typeUuid = $this->createType();
        $entryUuid = $this->seedRouteLessPublishedEntry($typeUuid, 'en', []);

        self::assertNull($this->reader()->findPublishedBlocks($entryUuid, '', 'fr'));
    }

    public function testCrossTenantEntryFailsClosed(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''");
        try {
            $typeUuid = $this->createType();
            $entryUuid = $this->seedRouteLessPublishedEntry($typeUuid, 'en', []);
            $this->connection()->table('entries')
                ->where('uuid', '=', $entryUuid)
                ->update(['tenant_uuid' => 'pebrtenantb1']);

            self::assertNull($this->reader()->findPublishedBlocks($entryUuid, 'pebrtenanta', 'en'));
            // Same-tenant read still resolves — proves the failure above is tenant-specific,
            // not a side effect of adding the column.
            self::assertNotNull($this->reader()->findPublishedBlocks($entryUuid, 'pebrtenantb1', 'en'));
        } finally {
            $pdo->exec('ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid');
        }
    }
}
