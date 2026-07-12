<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Exceptions\InvalidQueryException;
use Thallo\Collections\Query\ListResult;
use Thallo\Collections\Query\QueryCompiler;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * Integration tests for QueryCompiler — filter / sort / fields / offset pagination.
 *
 * The test creates a real collection via CollectionManager, seeds rows directly
 * into the materialized table, and asserts the compiled queries return the expected
 * slices, totals, and projections — or throw InvalidQueryException for bad input.
 */
final class QueryCompilerTest extends CollectionsTestCase
{
    private const COLLECTION_NAME = 'querycompilertest';

    private CollectionDefinition $def;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->reset();
        $this->cleanupCollection();

        // Create a collection with a mix of filterable and non-filterable field types.
        $this->def = $this->manager()->create([
            'name'   => self::COLLECTION_NAME,
            'label'  => 'Query Compiler Test',
            'fields' => [
                // Filterable + sortable
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
                ['name' => 'score', 'type' => 'collections.integer', 'settings' => ['nullable' => true]],
                ['name' => 'status', 'type' => 'collections.enum', 'settings' => [
                    'nullable' => true,
                    'values'   => ['draft', 'published'],
                ]],
                // NOT filterable, NOT sortable (longtext)
                ['name' => 'body', 'type' => 'collections.text', 'settings' => ['nullable' => true]],
                // NOT filterable, NOT sortable (json)
                ['name' => 'tags', 'type' => 'collections.json', 'settings' => ['nullable' => true]],
            ],
        ], 'admin', null);

        // Seed deterministic rows for assertion.
        $base = [
            'body'            => null,
            'tags'            => null,
            'status'          => null,
            'created_at'      => '2024-01-01 00:00:00',
            'updated_at'      => '2024-01-01 00:00:00',
            'created_by_type' => null,
            'created_by_id'   => null,
            'updated_by_type' => null,
            'updated_by_id'   => null,
        ];

        foreach (
            [
            ['uuid' => 'qct-uuid-0001', 'title' => 'Alpha',     'score' => 10],
            ['uuid' => 'qct-uuid-0002', 'title' => 'Beta',      'score' => 20],
            ['uuid' => 'qct-uuid-0003', 'title' => 'Gamma',     'score' => 30],
            ['uuid' => 'qct-uuid-0004', 'title' => 'Alpha Two', 'score' => 5],
            ] as $row
        ) {
            $this->connection()->table($this->def->tableName)->insert(array_merge($base, $row));
        }
    }

    protected function tearDown(): void
    {
        $this->schema()->reset();
        $this->cleanupCollection();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function compiler(): QueryCompiler
    {
        return $this->container()->get(QueryCompiler::class);
    }

    private function tableNameFor(string $name): string
    {
        return $this->physicalTable($name);
    }

    private function cleanupCollection(): void
    {
        $table = $this->tableNameFor(self::COLLECTION_NAME);
        if ($this->schema()->hasTable($table)) {
            $this->schema()->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')
            ->where('name', self::COLLECTION_NAME)
            ->delete();
        $this->connection()->table('collection_schema_changes')
            ->where('id', '>', 0)
            ->delete();
    }

    // ----------------------------------------------------------------- tests

    /**
     * No params → returns all 4 rows, page 1, default perPage, total 4.
     */
    public function testListWithNoParamsReturnsAllRows(): void
    {
        $result = $this->compiler()->list($this->def, []);

        self::assertInstanceOf(ListResult::class, $result);
        self::assertSame(1, $result->page);
        self::assertSame(20, $result->perPage); // default
        self::assertSame(4, $result->total);
        self::assertCount(4, $result->data);

        // uuid is always present in every row.
        foreach ($result->data as $row) {
            self::assertArrayHasKey('uuid', $row);
        }
    }

    /**
     * eq filter returns only the row whose title is exactly 'Alpha'.
     */
    public function testEqFilterReturnsMatchingRow(): void
    {
        $result = $this->compiler()->list($this->def, [
            'filter' => ['title' => ['eq' => 'Alpha']],
        ]);

        self::assertCount(1, $result->data);
        self::assertSame('Alpha', (string) $result->data[0]['title']);
        self::assertSame(1, $result->total);
    }

    /**
     * in filter returns rows whose title is in the given list.
     */
    public function testInFilterReturnsMatchingRows(): void
    {
        $result = $this->compiler()->list($this->def, [
            'filter' => ['title' => ['in' => ['Alpha', 'Beta']]],
        ]);

        self::assertCount(2, $result->data);
        self::assertSame(2, $result->total);

        $titles = array_column($result->data, 'title');
        sort($titles);
        self::assertSame(['Alpha', 'Beta'], $titles);
    }

    /**
     * like filter uses %value% and returns both 'Alpha' and 'Alpha Two'.
     */
    public function testLikeFilterReturnsMatchingRows(): void
    {
        $result = $this->compiler()->list($this->def, [
            'filter' => ['title' => ['like' => 'Alpha']],
        ]);

        self::assertCount(2, $result->data);
        self::assertSame(2, $result->total);

        $titles = array_column($result->data, 'title');
        foreach ($titles as $t) {
            self::assertStringContainsString('Alpha', (string) $t);
        }
    }

    /**
     * gt filter (score > 10) returns rows with score 20 and 30.
     */
    public function testGtFilterReturnsMatchingRows(): void
    {
        $result = $this->compiler()->list($this->def, [
            'filter' => ['score' => ['gt' => 10]],
        ]);

        self::assertCount(2, $result->data);
        self::assertSame(2, $result->total);

        foreach ($result->data as $row) {
            self::assertGreaterThan(10, (int) $row['score']);
        }
    }

    /**
     * -field sort (DESC) orders rows with the highest score first.
     */
    public function testDescSortOrdersRowsHighestFirst(): void
    {
        $result = $this->compiler()->list($this->def, [
            'sort' => '-score',
        ]);

        self::assertSame(4, $result->total);

        $scores = array_map(static fn ($r) => (int) $r['score'], $result->data);
        $expected = $scores;
        rsort($expected);
        self::assertSame($expected, $scores);
    }

    /**
     * Offset pagination: page 2 with perPage 2 returns 2 rows and total = 4.
     */
    public function testOffsetPaginationReturnsCorrectSliceAndTotal(): void
    {
        $result = $this->compiler()->list($this->def, [
            'page'    => 2,
            'perPage' => 2,
            'sort'    => 'score', // deterministic ordering
        ]);

        self::assertSame(2, $result->page);
        self::assertSame(2, $result->perPage);
        self::assertSame(4, $result->total);
        self::assertCount(2, $result->data);

        // Page 2 (offset 2) with score ASC: scores 10, 20 on page 1; 30, 5? No:
        // score ASC: 5, 10, 20, 30 → page 1: [5, 10], page 2: [20, 30]
        $scores = array_map(static fn ($r) => (int) $r['score'], $result->data);
        self::assertContains(20, $scores);
        self::assertContains(30, $scores);
    }

    /**
     * Filtering an unknown field (not in the definition) throws InvalidQueryException.
     */
    public function testFilteringUnknownFieldThrowsInvalidQueryException(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->compiler()->list($this->def, [
            'filter' => ['nonexistent_field' => ['eq' => 'x']],
        ]);
    }

    /**
     * Sorting by a non-sortable field (longtext → sortable=false) throws InvalidQueryException.
     */
    public function testSortingNonSortableFieldThrowsInvalidQueryException(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->compiler()->list($this->def, [
            'sort' => 'body', // collections.text: sortable=false
        ]);
    }

    /**
     * Sorting by a JSON field (collections.json → sortable=false) throws InvalidQueryException.
     */
    public function testSortingJsonFieldThrowsInvalidQueryException(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->compiler()->list($this->def, [
            'sort' => '-tags', // collections.json: sortable=false
        ]);
    }

    /**
     * Filtering a non-filterable field (longtext → filterable=false) throws InvalidQueryException.
     */
    public function testFilteringNonFilterableFieldThrowsInvalidQueryException(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->compiler()->list($this->def, [
            'filter' => ['body' => ['eq' => 'some text']], // collections.text: filterable=false
        ]);
    }

    /**
     * perPage is capped at max_per_page (default 100) regardless of the requested value.
     */
    public function testPerPageIsCappedAtMaxPerPage(): void
    {
        $result = $this->compiler()->list($this->def, [
            'perPage' => 9999,
        ]);

        self::assertLessThanOrEqual(100, $result->perPage);
        // Data still returns correctly (all 4 rows are within the cap)
        self::assertSame(4, $result->total);
    }

    /**
     * fields projection restricts SELECT columns; uuid is always included.
     */
    public function testFieldProjectionRestrictsColumnsAndUuidIsAlwaysPresent(): void
    {
        $result = $this->compiler()->list($this->def, [
            'fields' => 'title',
        ]);

        self::assertNotEmpty($result->data);

        foreach ($result->data as $row) {
            self::assertArrayHasKey('uuid', $row, 'uuid must always be projected');
            self::assertArrayHasKey('title', $row);
            self::assertArrayNotHasKey('score', $row, 'score was not requested');
            self::assertArrayNotHasKey('body', $row, 'body was not requested');
        }
    }

    /**
     * Requesting an unknown field in the fields projection throws InvalidQueryException.
     */
    public function testUnknownFieldInProjectionThrowsInvalidQueryException(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->compiler()->list($this->def, [
            'fields' => 'title,nonexistent_column',
        ]);
    }

    /**
     * System columns (uuid, created_at, updated_at) are sortable.
     */
    public function testSystemColumnsSortableByCreatedAt(): void
    {
        $result = $this->compiler()->list($this->def, [
            'sort' => '-created_at',
        ]);

        self::assertSame(4, $result->total);
    }

    /**
     * System columns are filterable: filter by uuid returns exactly one row.
     */
    public function testSystemColumnFilterableByUuid(): void
    {
        $result = $this->compiler()->list($this->def, [
            'filter' => ['uuid' => ['eq' => 'qct-uuid-0001']],
        ]);

        self::assertCount(1, $result->data);
        self::assertSame('qct-uuid-0001', (string) $result->data[0]['uuid']);
        self::assertSame(1, $result->total);
    }
}
