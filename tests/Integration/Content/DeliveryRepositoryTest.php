<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\Cursor;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Schema\FieldDescriptor;
use App\Content\Delivery\SortCompiler;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;

final class DeliveryRepositoryTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
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

    private function publish(string $title, string $slug): string
    {
        return $this->publishInType($this->type, $title, $slug);
    }

    private function publishInType(string $typeUuid, string $title, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $typeUuid, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    private function repo(): DeliveryRepository
    {
        return new DeliveryRepository($this->connection());
    }

    public function testListReturnsOnlyPublished(): void
    {
        $this->publish('Live', 'live');
        // an unpublished entry (draft saved, never published)
        $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        $rows = $this->repo()->listPublished($this->type, 'en', limit: 20);

        self::assertCount(1, $rows);
        self::assertSame('Live', $rows[0]['fields']['title']);
    }

    public function testFindBySlugReturnsPublishedVersion(): void
    {
        $this->publish('Hello', 'hello');

        $row = $this->repo()->findPublishedByRoute($this->type, 'en', 'hello');

        self::assertNotNull($row);
        self::assertSame('Hello', $row['fields']['title']);
        self::assertArrayHasKey('version_uuid', $row);
        self::assertNull($this->repo()->findPublishedByRoute($this->type, 'en', 'does-not-exist'));
    }

    public function testFindByUuidReturnsOnlyPublished(): void
    {
        $published = $this->publish('Visible', 'visible');
        $draftOnly = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        $found = $this->repo()->findPublishedByUuid($this->type, 'en', $published);
        self::assertNotNull($found);
        self::assertSame('Visible', $found['fields']['title']);
        self::assertNull($this->repo()->findPublishedByUuid($this->type, 'en', $draftOnly));
    }

    public function testPublishedByEntryUuidsBatchExcludesUnpublished(): void
    {
        $published = $this->publish('Batch', 'batch');
        $draftOnly = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        // read:content = full access, so the (non-public) post type resolves normally here.
        $out = $this->repo()->publishedByEntryUuids([$published, $draftOnly], 'en', ['read:content']);

        self::assertArrayHasKey($published, $out);
        self::assertArrayNotHasKey($draftOnly, $out);
        self::assertSame('Batch', $out[$published]['fields']['title']);
    }

    public function testPublishedByEntryUuidsFiltersByTypeVisibility(): void
    {
        // A public type and a NON-public type, one published entry each.
        $publicType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'public-post', 'name' => 'Public', 'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $privateType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'secret', 'name' => 'Secret', 'public_delivery' => false,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $pub = $this->publishInType($publicType, 'Public One', 'public-one');
        $priv = $this->publishInType($privateType, 'Secret One', 'secret-one');

        // Anonymous (null scopes): only the public type's entry resolves.
        $anon = $this->repo()->publishedByEntryUuids([$pub, $priv], 'en', null);
        self::assertArrayHasKey($pub, $anon);
        self::assertArrayNotHasKey($priv, $anon, 'a non-public type must not resolve for an anonymous caller');

        // read:content:secret → the private type resolves too.
        $scoped = $this->repo()->publishedByEntryUuids([$pub, $priv], 'en', ['read:content:secret']);
        self::assertArrayHasKey($pub, $scoped);
        self::assertArrayHasKey($priv, $scoped);
        // The visibility metadata columns never leak into the resolved row.
        self::assertArrayNotHasKey('_public', $scoped[$priv]);
        self::assertArrayNotHasKey('_type_slug', $scoped[$priv]);
    }

    public function testListAppliesCompiledNumberFilter(): void
    {
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'price', 'type' => 'number',
                    'filterable' => true, 'filter_type' => 'number'],
            ],
        ]);
        $this->publishFields($type, ['title' => 'Cheap', 'price' => 5], 'cheap');
        $this->publishFields($type, ['title' => 'Pricey', 'price' => 50], 'pricey');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $resolver = new class implements ReferenceTargetResolver {
            public function resolve(FieldDescriptor $field, string $locale, array $values): array
            {
                return [];
            }
        };
        $filter = (new FilterCompiler($resolver))->compile($schema, ['price' => ['gt' => '10']], 'en');

        $rows = $this->repo()->listPublished($type, 'en', limit: 20, filter: $filter);

        self::assertCount(1, $rows);
        self::assertSame('Pricey', $rows[0]['fields']['title']);
    }

    private function productType(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
            ],
        ]);
    }

    public function testSortByFilterableFieldAscending(): void
    {
        $type = $this->productType();
        $this->publishFields($type, ['title' => 'B', 'price' => 30], 'b');
        $this->publishFields($type, ['title' => 'A', 'price' => 10], 'a');
        $this->publishFields($type, ['title' => 'C', 'price' => 20], 'c');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $order = (new SortCompiler())->compile($schema, 'price:asc');

        $rows = $this->repo()->listPublished($type, 'en', limit: 20, order: $order);

        self::assertSame(
            [10.0, 20.0, 30.0],
            array_map(static fn(array $r): float => (float) $r['fields']['price'], $rows)
        );
    }

    public function testKeysetCursorReturnsNextPageWithoutDuplicatesOrSkips(): void
    {
        $type = $this->productType();
        // Default order is published_at DESC, v.id DESC: publish 1..5 in order so
        // newest (E) sorts first.
        foreach (['A', 'B', 'C', 'D', 'E'] as $i => $title) {
            $this->publishFields($type, ['title' => $title, 'price' => $i], strtolower($title));
        }

        $order = SortCompiler::defaultOrder();

        $page1 = $this->repo()->listPublished($type, 'en', limit: 2, order: $order);
        self::assertCount(2, $page1);

        $cursor = $this->repo()->cursorFor($page1[1], $order);
        $token = Cursor::encode($cursor);

        $page2 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode($token)
        );
        self::assertCount(2, $page2);

        $cursor2 = $this->repo()->cursorFor($page2[1], $order);
        $page3 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode(Cursor::encode($cursor2))
        );
        self::assertCount(1, $page3);

        // No dupes / no skips: the three pages cover all 5 distinct entries in order.
        $titles = [];
        foreach ([...$page1, ...$page2, ...$page3] as $row) {
            $titles[] = $row['fields']['title'];
        }
        self::assertSame(['E', 'D', 'C', 'B', 'A'], $titles);
        self::assertSame($titles, array_values(array_unique($titles)));
    }

    public function testKeysetCursorWithFieldSortPaginates(): void
    {
        $type = $this->productType();
        $this->publishFields($type, ['title' => 'p10', 'price' => 10], 'p10');
        $this->publishFields($type, ['title' => 'p20', 'price' => 20], 'p20');
        $this->publishFields($type, ['title' => 'p30', 'price' => 30], 'p30');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $order = (new SortCompiler())->compile($schema, 'price:asc');

        $page1 = $this->repo()->listPublished($type, 'en', limit: 2, order: $order);
        self::assertSame([10.0, 20.0], array_map(
            static fn(array $r): float => (float) $r['fields']['price'],
            $page1
        ));

        $cursor = $this->repo()->cursorFor($page1[1], $order);
        $page2 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode(Cursor::encode($cursor))
        );
        self::assertSame([30.0], array_map(
            static fn(array $r): float => (float) $r['fields']['price'],
            $page2
        ));
    }

    public function testKeysetCursorIncludesRowsMissingTheSortedField(): void
    {
        // price is optional + filterable: one entry omits it entirely (sorts NULL).
        $type = $this->productType();
        $this->publishFields($type, ['title' => 'p10', 'price' => 10], 'p10');
        $this->publishFields($type, ['title' => 'p20', 'price' => 20], 'p20');
        $this->publishFields($type, ['title' => 'noprice'], 'noprice');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $order = (new SortCompiler())->compile($schema, 'price:asc');

        // Page 1: the two priced entries, ascending.
        $page1 = $this->repo()->listPublished($type, 'en', limit: 2, order: $order);
        self::assertSame(['p10', 'p20'], array_map(
            static fn(array $r): string => (string) $r['fields']['title'],
            $page1
        ));

        // Page 2: the missing-field row must NOT be dropped — it sorts last (NULLS LAST).
        $page2 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode(Cursor::encode($this->repo()->cursorFor($page1[1], $order)))
        );
        self::assertSame(['noprice'], array_map(
            static fn(array $r): string => (string) $r['fields']['title'],
            $page2
        ));

        // Page 3: paging past the null tail terminates cleanly (no infinite loop).
        $page3 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode(Cursor::encode($this->repo()->cursorFor($page2[0], $order)))
        );
        self::assertSame([], $page3);
    }

    public function testKeysetCursorDescendingIncludesRowsMissingTheSortedField(): void
    {
        $type = $this->productType();
        $this->publishFields($type, ['title' => 'p10', 'price' => 10], 'p10');
        $this->publishFields($type, ['title' => 'p20', 'price' => 20], 'p20');
        $this->publishFields($type, ['title' => 'noprice'], 'noprice');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $order = (new SortCompiler())->compile($schema, 'price:desc');

        // DESC NULLS LAST: 20, 10, then the missing-field row.
        $page1 = $this->repo()->listPublished($type, 'en', limit: 2, order: $order);
        self::assertSame(['p20', 'p10'], array_map(
            static fn(array $r): string => (string) $r['fields']['title'],
            $page1
        ));

        $page2 = $this->repo()->listPublished(
            $type,
            'en',
            limit: 2,
            order: $order,
            cursor: Cursor::decode(Cursor::encode($this->repo()->cursorFor($page1[1], $order)))
        );
        self::assertSame(['noprice'], array_map(
            static fn(array $r): string => (string) $r['fields']['title'],
            $page2
        ));
    }

    public function testKeysetCursorPagesThroughMultipleNullSortRows(): void
    {
        // Two missing-field rows in the null tail: paging must cover both, once each.
        $type = $this->productType();
        $this->publishFields($type, ['title' => 'p10', 'price' => 10], 'p10');
        $this->publishFields($type, ['title' => 'noA'], 'no-a');
        $this->publishFields($type, ['title' => 'noB'], 'no-b');

        $schema = (new ContentTypeRepository($this->connection()))->schemaFor($type);
        $order = (new SortCompiler())->compile($schema, 'price:asc');

        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            $rows = $this->repo()->listPublished($type, 'en', limit: 1, order: $order, cursor: $cursor);
            if ($rows === []) {
                break;
            }
            $seen[] = (string) $rows[0]['fields']['title'];
            $cursor = Cursor::decode(Cursor::encode($this->repo()->cursorFor($rows[0], $order)));
        }

        // p10 first (only non-null), then both null-tail rows, each exactly once.
        self::assertSame('p10', $seen[0]);
        $tail = array_slice($seen, 1);
        sort($tail);
        self::assertSame(['noA', 'noB'], $tail);
        self::assertSame($seen, array_values(array_unique($seen)));
    }

    public function testPaginatePublishedReturnsFrameworkShape(): void
    {
        $type = $this->productType();
        foreach (['A', 'B', 'C'] as $i => $title) {
            $this->publishFields($type, ['title' => $title, 'price' => $i], strtolower($title));
        }

        $result = $this->repo()->paginatePublished($type, 'en', page: 1, perPage: 2);

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('total', $result);
        self::assertArrayHasKey('current_page', $result);
        self::assertArrayHasKey('per_page', $result);
        self::assertSame(3, $result['total']);
        self::assertSame(1, $result['current_page']);
        self::assertSame(2, $result['per_page']);
        self::assertCount(2, $result['data']);
        // fields are hydrated (decoded JSONB), not raw strings.
        self::assertIsArray($result['data'][0]['fields']);
    }

    public function testPaginatePublishedSecondPageHasRemainder(): void
    {
        $type = $this->productType();
        foreach (['A', 'B', 'C'] as $i => $title) {
            $this->publishFields($type, ['title' => $title, 'price' => $i], strtolower($title));
        }

        $result = $this->repo()->paginatePublished($type, 'en', page: 2, perPage: 2);

        self::assertSame(2, $result['current_page']);
        self::assertCount(1, $result['data']);
        self::assertSame(3, $result['total']);
    }

    /** @param array<string,mixed> $fields */
    private function publishFields(string $type, array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }
}
