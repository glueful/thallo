<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\ExpandedTargets;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\HttpFoundation\Request;

final class BlockReferenceExpansionTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        // related: a single ref + a multi ref + an asset; nest: a container.
        $blocks->create(['slug' => 'related', 'label' => 'Related', 'schema' => [
            ['name' => 'post', 'type' => 'reference'],
            ['name' => 'more', 'type' => 'reference', 'multiple' => true],
            ['name' => 'image', 'type' => 'asset'],
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

    /** @param array<string,mixed> $fields */
    private function createPublished(array $fields, string $slug): string
    {
        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            // FULL validator: blocks fields REQUIRE the registry — a bare
            // `new FieldValidator()` rejects every blocks value with
            // "block types are unavailable".
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /**
     * A real blob row: with the full validator wired, validateAt() applies
     * assetExistsOnMediaDisk() to asset fields INSIDE blocks too, so the fixture's
     * asset uuid must exist on the configured media disk ('local' default).
     */
    private function seedBlob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'img.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'storage_type' => 'local',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => 'user00000001',
            'created_at' => '2026-07-01 00:00:00',
        ]);
    }

    private function resolver(): ReferenceResolver
    {
        return new ReferenceResolver(
            new DeliveryRepository($this->connection()),
            new BlockTypeRepository($this->connection()),
        );
    }

    private function schema(): ContentTypeSchema
    {
        return (new ContentTypeRepository($this->connection()))->schemaFor($this->type);
    }

    /** @return array<string,mixed> */
    private function expandOne(string $entryUuid, ?FieldSelector $selector = null, ?ExpandedTargets $t = null): array
    {
        $row = (new DeliveryRepository($this->connection()))
            ->findPublishedByUuid($this->type, 'en', $entryUuid);
        return $this->resolver()->expand([$row], $this->schema(), $selector, 'en', 2, null, $t)[0];
    }

    public function testBlockReferenceExpandsInPlaceWithTopLevelShape(): void
    {
        $this->seedBlob('blobimg00001');
        $target = $this->createPublished(['title' => 'Target', 'body' => []], 'target');
        $source = $this->createPublished(['title' => 'Source', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => [
                'post' => $target,
                'more' => [$target],
                'image' => 'blobimg00001',
            ]],
        ]], 'source');

        $expanded = new ExpandedTargets();
        $fields = $this->expandOne($source, null, $expanded)['fields'];
        $data = $fields['body'][0]['data'];

        // Single ref: hydrated-row shape, identical to top-level expansion.
        self::assertSame('Target', $data['post']['fields']['title']);
        self::assertSame($target, $data['post']['entry_uuid']);
        self::assertArrayHasKey('version_uuid', $data['post']);
        // Multi ref: ordered list of expanded items.
        self::assertSame('Target', $data['more'][0]['fields']['title']);
        // Asset: raw blob uuid, never expanded (spec §1 hard boundary).
        self::assertSame('blobimg00001', $data['image']);
        // Collector saw the target exactly once (deduped across post+more).
        self::assertSame([$target], $expanded->entryUuids());
    }

    public function testNestedBlocksExpandAndStructuralDepthIsNotReferenceDepth(): void
    {
        $target = $this->createPublished(['title' => 'Deep', 'body' => []], 'deep');
        // 3 structural levels (nest > nest > related) — the ref at the bottom MUST
        // expand: structure is layout, not graph hops (spec §1 depth model).
        $source = $this->createPublished(['title' => 'S', 'body' => [
            ['id' => 'n1', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'n2', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'r1', 'type' => 'related', 'data' => ['post' => $target]],
                ]]],
            ]]],
        ]], 's');

        $fields = $this->expandOne($source)['fields'];
        $bottom = $fields['body'][0]['data']['inner'][0]['data']['inner'][0]['data'];
        self::assertSame('Deep', $bottom['post']['fields']['title']);
    }

    public function testMalformedDataDirectlyThroughTheResolverIsLeftUntouched(): void
    {
        // Hand-built hydrated rows (publish validation would reject all of these —
        // the FULL validator rejects unknown slugs and malformed items): the
        // resolver's OWN guards must hold for data written around the API
        // (spec §2 robustness). Nothing throws; nothing is modified.
        $body = [
            'not-a-block',                                                    // non-array item
            ['id' => 'a', 'type' => 42, 'data' => ['post' => 'entryzzzz001']], // non-string type
            ['id' => 'g', 'type' => 'ghost', 'data' => ['post' => 'entryghost01']], // unknown slug
            ['id' => 'b', 'type' => 'related', 'data' => 'scalar-data'],      // non-array data
            ['id' => 'c', 'type' => 'related'],                               // data missing
        ];
        $row = ['entry_uuid' => 'handmade0001', 'version_uuid' => 'handmadev001',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => $body]];
        $out = $this->resolver()->expand([$row], $this->schema(), null, 'en')[0];
        self::assertSame($body, $out['fields']['body']);

        // Non-list blocks value: untouched.
        $row2 = ['entry_uuid' => 'handmade0002', 'version_uuid' => 'handmadev002',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => 'nope']];
        self::assertSame(
            'nope',
            $this->resolver()->expand([$row2], $this->schema(), null, 'en')[0]['fields']['body'],
        );

        // Structural cap: refs BELOW BlockDepth::MAX (4 nest levels) stay raw.
        $deep = ['id' => 'r', 'type' => 'related', 'data' => ['post' => 'entrydeep001']];
        $lvl = $deep;
        for ($i = 0; $i < 3; $i++) { // wrap 3x -> the ref sits at structural level 4
            $lvl = ['id' => "n{$i}", 'type' => 'nest', 'data' => ['inner' => [$lvl]]];
        }
        $row3 = ['entry_uuid' => 'handmade0003', 'version_uuid' => 'handmadev003',
            'version' => 1, 'fields' => ['title' => 'X', 'body' => [$lvl]]];
        $out3 = $this->resolver()->expand([$row3], $this->schema(), null, 'en')[0];
        $bottom = $out3['fields']['body'][0]['data']['inner'][0]['data']['inner'][0]['data']['inner'][0];
        self::assertSame('entrydeep001', $bottom['data']['post']); // raw — walk capped
    }

    public function testSelectorScopesAtTheBlocksFieldLevel(): void
    {
        $target = $this->createPublished(['title' => 'T', 'body' => []], 't');
        $source = $this->createPublished(['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 's3');

        // fields=title → body is not walked at all: raw uuid.
        $sel = FieldSelector::fromRequest(Request::create('/?fields=title'));
        $fields = $this->expandOne($source, $sel)['fields'];
        self::assertSame($target, $fields['body'][0]['data']['post']);

        // fields=body → block refs expand.
        $sel = FieldSelector::fromRequest(Request::create('/?fields=body'));
        $fields = $this->expandOne($source, $sel)['fields'];
        self::assertSame('T', $fields['body'][0]['data']['post']['fields']['title']);
    }

    public function testDepthCapLeavesRawUuidInsideBlocks(): void
    {
        // A -> (block ref) B -> (block ref) C with depth 1: B expands inside A,
        // B's own inner block ref to C stays raw (hop 2 capped).
        $c = $this->createPublished(['title' => 'C', 'body' => []], 'c');
        $b = $this->createPublished(['title' => 'B', 'body' => [
            ['id' => 'x', 'type' => 'related', 'data' => ['post' => $c]],
        ]], 'b');
        $a = $this->createPublished(['title' => 'A', 'body' => [
            ['id' => 'y', 'type' => 'related', 'data' => ['post' => $b]],
        ]], 'a');

        $row = (new DeliveryRepository($this->connection()))->findPublishedByUuid($this->type, 'en', $a);
        $fields = $this->resolver()->expand([$row], $this->schema(), null, 'en', 1)[0]['fields'];
        $bItem = $fields['body'][0]['data']['post'];
        self::assertSame('B', $bItem['fields']['title']);          // hop 1 expanded
        self::assertSame($c, $bItem['fields']['body'][0]['data']['post']); // hop 2 capped: raw
    }
}
