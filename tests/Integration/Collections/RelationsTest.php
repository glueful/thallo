<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Events\EventService;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Exceptions\CollectionExpandForbiddenException;
use Thallo\Collections\Exceptions\RowReferencedException;
use Thallo\Collections\Exceptions\RowValidationException;
use Thallo\Collections\Relations\RelationResolver;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * Integration tests for Task 10: relation validation-on-write, bounded expand, restrict-delete,
 * and row change events.
 *
 * Three collections are created per test:
 *   - rel_test_authors  (no relations, used as a target)
 *   - rel_test_articles (single + multi relation to authors)
 *   - rel_test_nested   (single relation to articles — for no-recurse expand test)
 */
final class RelationsTest extends CollectionsTestCase
{
    private const AUTHORS_COLLECTION  = 'rel_test_authors';
    private const ARTICLES_COLLECTION = 'rel_test_articles';
    private const NESTED_COLLECTION   = 'rel_test_nested';

    private CollectionDefinition $authors;
    private CollectionDefinition $articles;
    private CollectionDefinition $nested;

    /** @var list<object> Captured events from the recording listener */
    private array $capturedEvents = [];

    // ----------------------------------------------------------------- setUp / tearDown

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->reset();
        $this->cleanupAll();

        $mgr = $this->manager();

        // Target collection: no relation fields.
        $this->authors = $mgr->create([
            'name'   => self::AUTHORS_COLLECTION,
            'label'  => 'Rel Test Authors',
            'fields' => [
                ['name' => 'name', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
            ],
        ], 'admin', 'u1');

        // Collection with a single AND a multi relation pointing at authors.
        $this->articles = $mgr->create([
            'name'   => self::ARTICLES_COLLECTION,
            'label'  => 'Rel Test Articles',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
                ['name' => 'author', 'type' => 'collections.relation', 'settings' => [
                    'nullable' => true,
                    'target'   => 'collection:' . self::AUTHORS_COLLECTION,
                    'multi'    => false,
                ]],
                ['name' => 'co_authors', 'type' => 'collections.relation', 'settings' => [
                    'nullable' => true,
                    'target'   => 'collection:' . self::AUTHORS_COLLECTION,
                    'multi'    => true,
                ]],
            ],
        ], 'admin', 'u1');

        // Collection that points to articles — used for the no-recurse expand test.
        $this->nested = $mgr->create([
            'name'   => self::NESTED_COLLECTION,
            'label'  => 'Rel Test Nested',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
                ['name' => 'article', 'type' => 'collections.relation', 'settings' => [
                    'nullable' => true,
                    'target'   => 'collection:' . self::ARTICLES_COLLECTION,
                    'multi'    => false,
                ]],
            ],
        ], 'admin', 'u1');

        // Register a recording listener for all three event classes.
        // Each test run adds one closure per class; old closures write to old (GC'd) arrays
        // because they capture a reference to the previous test's $capturedEvents property.
        // The current test's listener is the only one writing to $this->capturedEvents.
        $events     = $this->container()->get(EventService::class);
        $captureRef = &$this->capturedEvents;
        $record     = static function (object $event) use (&$captureRef): void {
            $captureRef[] = $event;
        };
        $events->addListener(CollectionRowCreated::class, $record);
        $events->addListener(CollectionRowUpdated::class, $record);
        $events->addListener(CollectionRowDeleted::class, $record);
    }

    protected function tearDown(): void
    {
        $this->schema()->reset();
        $this->cleanupAll();
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

    private function repo(): RowRepository
    {
        return $this->container()->get(RowRepository::class);
    }

    private function resolver(): RelationResolver
    {
        return $this->container()->get(RelationResolver::class);
    }

    /** A target-read gate that authorizes every expansion — for tests exercising mechanics only. */
    private static function allowAll(): callable
    {
        return static fn (): bool => true;
    }

    private function actor(): Actor
    {
        return new Actor('admin', 'u1');
    }

    private function tableNameFor(string $name): string
    {
        return $this->physicalTable($name);
    }

    private function cleanupAll(): void
    {
        $schema = $this->schema();
        foreach ([self::AUTHORS_COLLECTION, self::ARTICLES_COLLECTION, self::NESTED_COLLECTION] as $name) {
            $table = $this->tableNameFor($name);
            if ($schema->hasTable($table)) {
                $schema->dropTableIfExists($table);
            }
            $this->connection()->table('collection_definitions')->where('name', $name)->delete();
        }
        $this->connection()->table('collection_schema_changes')->where('id', '>', 0)->delete();
    }

    // ----------------------------------------------------------------- validate-on-write

    /**
     * A single-relation field whose target uuid does not exist in the target table
     * must throw RowValidationException with an error keyed by the field name.
     */
    public function testSingleRelationRejectsNonExistentTargetUuid(): void
    {
        $caught = null;
        try {
            $this->repo()->create(
                $this->articles,
                ['title' => 'Hello', 'author' => 'nonexistent-uuid-0001'],
                $this->actor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown for a non-existent relation target');
        self::assertArrayHasKey('author', $caught->errors());
    }

    /**
     * A multi-relation field rejects input containing a uuid absent from the target table.
     */
    public function testMultiRelationRejectsNonExistentTargetUuid(): void
    {
        $realAuthor = $this->repo()->create($this->authors, ['name' => 'Real Author'], $this->actor());

        $caught = null;
        try {
            $this->repo()->create(
                $this->articles,
                [
                    'title'      => 'Hello',
                    'co_authors' => [(string) $realAuthor['uuid'], 'ghost-uuid-000'],
                ],
                $this->actor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'RowValidationException must be thrown when one target uuid is missing');
        self::assertArrayHasKey('co_authors', $caught->errors());
    }

    /**
     * A nullable relation field absent from the input must be accepted without error.
     */
    public function testNullableRelationAllowsOmittedField(): void
    {
        $row = $this->repo()->create(
            $this->articles,
            ['title' => 'No Author'],
            $this->actor(),
        );

        self::assertNotEmpty($row['uuid']);
    }

    // ----------------------------------------------------------------- expand

    /**
     * expand() replaces a single-relation field's uuid with the full target row.
     */
    public function testExpandResolvesSingleRelation(): void
    {
        $author  = $this->repo()->create($this->authors, ['name' => 'Alice'], $this->actor());
        $article = $this->repo()->create(
            $this->articles,
            ['title' => 'My Article', 'author' => (string) $author['uuid']],
            $this->actor(),
        );

        $expanded = $this->resolver()->expand($this->articles, [$article], ['author'], self::allowAll());

        self::assertIsArray($expanded[0]['author'], 'Expanded single relation must be an array (the target row)');
        self::assertSame((string) $author['uuid'], (string) $expanded[0]['author']['uuid']);
        self::assertSame('Alice', (string) $expanded[0]['author']['name']);
        // Only the safe column allow-list is projected — never the internal auto-increment id.
        self::assertArrayNotHasKey('id', $expanded[0]['author'], 'internal id must not leak through expansion');
    }

    public function testExpandThrowsWhenTargetCollectionIsUnreadable(): void
    {
        $author  = $this->repo()->create($this->authors, ['name' => 'Eve'], $this->actor());
        $article = $this->repo()->create(
            $this->articles,
            ['title' => 'Gated', 'author' => (string) $author['uuid']],
            $this->actor(),
        );

        // Caller cannot read the target collection → explicit ?expand is a 403-mapped error,
        // not a silent passthrough of the raw uuid.
        $this->expectException(CollectionExpandForbiddenException::class);
        $this->resolver()->expand($this->articles, [$article], ['author'], static fn (): bool => false);
    }

    /**
     * expand() replaces a multi-relation field's JSON-encoded uuids with the full target rows.
     */
    public function testExpandResolvesMultiRelation(): void
    {
        $a1      = $this->repo()->create($this->authors, ['name' => 'Bob'], $this->actor());
        $a2      = $this->repo()->create($this->authors, ['name' => 'Carol'], $this->actor());
        $article = $this->repo()->create(
            $this->articles,
            ['title' => 'Multi Author', 'co_authors' => [(string) $a1['uuid'], (string) $a2['uuid']]],
            $this->actor(),
        );

        $expanded = $this->resolver()->expand($this->articles, [$article], ['co_authors'], self::allowAll());

        self::assertIsArray($expanded[0]['co_authors']);
        self::assertCount(2, $expanded[0]['co_authors']);
        $names = array_column($expanded[0]['co_authors'], 'name');
        self::assertContains('Bob', $names);
        self::assertContains('Carol', $names);
    }

    /**
     * expand() must NOT recurse: expanding nested.article gives the article row, but the
     * article's own 'author' field stays as a raw uuid string (not further expanded).
     */
    public function testExpandDoesNotRecurseIntoExpandedRelations(): void
    {
        $author  = $this->repo()->create($this->authors, ['name' => 'Dave'], $this->actor());
        $article = $this->repo()->create(
            $this->articles,
            ['title' => 'Some Article', 'author' => (string) $author['uuid']],
            $this->actor(),
        );
        $nested  = $this->repo()->create(
            $this->nested,
            ['title' => 'Nested Row', 'article' => (string) $article['uuid']],
            $this->actor(),
        );

        $expanded = $this->resolver()->expand($this->nested, [$nested], ['article'], self::allowAll());

        self::assertIsArray($expanded[0]['article'], 'expanded article must be an array');
        self::assertSame((string) $article['uuid'], (string) $expanded[0]['article']['uuid']);

        // The article's own 'author' field must remain a raw uuid — no recursion.
        self::assertSame(
            (string) $author['uuid'],
            (string) $expanded[0]['article']['author'],
            'expand must NOT recurse: article.author should remain a uuid string',
        );
    }

    // ----------------------------------------------------------------- restrict-delete

    /**
     * Deleting a row that is referenced by a single-relation field must throw RowReferencedException.
     */
    public function testDeleteRowReferencedBySingleRelationThrowsRowReferencedException(): void
    {
        $author = $this->repo()->create($this->authors, ['name' => 'Eve'], $this->actor());
        $this->repo()->create(
            $this->articles,
            ['title' => 'Eve Article', 'author' => (string) $author['uuid']],
            $this->actor(),
        );

        $this->expectException(RowReferencedException::class);
        $this->repo()->delete($this->authors, (string) $author['uuid'], $this->actor());
    }

    /**
     * Deleting a row that is referenced by a multi-relation field must throw RowReferencedException.
     */
    public function testDeleteRowReferencedByMultiRelationThrowsRowReferencedException(): void
    {
        $author = $this->repo()->create($this->authors, ['name' => 'Frank'], $this->actor());
        $this->repo()->create(
            $this->articles,
            ['title' => 'Frank Article', 'co_authors' => [(string) $author['uuid']]],
            $this->actor(),
        );

        $this->expectException(RowReferencedException::class);
        $this->repo()->delete($this->authors, (string) $author['uuid'], $this->actor());
    }

    /**
     * Deleting a row that is NOT referenced by any collection must succeed.
     */
    public function testDeleteUnreferencedRowSucceeds(): void
    {
        $author = $this->repo()->create($this->authors, ['name' => 'Grace'], $this->actor());

        $this->repo()->delete($this->authors, (string) $author['uuid'], $this->actor());

        self::assertTrue(true); // reaching here means no exception was thrown
    }

    // ----------------------------------------------------------------- events

    /**
     * RowRepository::create() must dispatch CollectionRowCreated carrying
     * collectionName, rowUuid, and the full row array.
     */
    public function testCreateDispatchesCollectionRowCreatedEvent(): void
    {
        $this->capturedEvents = [];

        $row = $this->repo()->create($this->authors, ['name' => 'Heidi'], $this->actor());

        $created = array_values(array_filter(
            $this->capturedEvents,
            static fn (object $e): bool => $e instanceof CollectionRowCreated,
        ));

        self::assertCount(1, $created, 'CollectionRowCreated must be dispatched exactly once on create');
        self::assertSame(self::AUTHORS_COLLECTION, $created[0]->collectionName);
        self::assertSame((string) $row['uuid'], $created[0]->rowUuid);
        self::assertIsArray($created[0]->row);
        self::assertSame((string) $row['uuid'], (string) $created[0]->row['uuid']);
        self::assertSame('admin', $created[0]->actor->type);
        self::assertSame('u1', $created[0]->actor->id);
    }

    /**
     * RowRepository::update() must dispatch CollectionRowUpdated carrying
     * collectionName, rowUuid, and the updated row array.
     */
    public function testUpdateDispatchesCollectionRowUpdatedEvent(): void
    {
        $row = $this->repo()->create($this->authors, ['name' => 'Ivan'], $this->actor());
        $this->capturedEvents = [];

        $updated = $this->repo()->update(
            $this->authors,
            (string) $row['uuid'],
            ['name' => 'Ivan Updated'],
            $this->actor(),
        );

        $events = array_values(array_filter(
            $this->capturedEvents,
            static fn (object $e): bool => $e instanceof CollectionRowUpdated,
        ));

        self::assertCount(1, $events, 'CollectionRowUpdated must be dispatched exactly once on update');
        self::assertSame(self::AUTHORS_COLLECTION, $events[0]->collectionName);
        self::assertSame((string) $row['uuid'], $events[0]->rowUuid);
        self::assertSame('Ivan Updated', (string) $events[0]->row['name']);
        self::assertSame('admin', $events[0]->actor->type);
        self::assertSame('u1', $events[0]->actor->id);
    }

    /**
     * RowRepository::delete() must dispatch CollectionRowDeleted carrying
     * collectionName and rowUuid (no row array — the row is gone).
     */
    public function testDeleteDispatchesCollectionRowDeletedEvent(): void
    {
        $row = $this->repo()->create($this->authors, ['name' => 'Judy'], $this->actor());
        $this->capturedEvents = [];

        $this->repo()->delete($this->authors, (string) $row['uuid'], $this->actor());

        $events = array_values(array_filter(
            $this->capturedEvents,
            static fn (object $e): bool => $e instanceof CollectionRowDeleted,
        ));

        self::assertCount(1, $events, 'CollectionRowDeleted must be dispatched exactly once on delete');
        self::assertSame(self::AUTHORS_COLLECTION, $events[0]->collectionName);
        self::assertSame((string) $row['uuid'], $events[0]->rowUuid);
        // The deleting actor is carried for audit attribution (the row itself is gone).
        self::assertSame('admin', $events[0]->actor->type);
        self::assertSame('u1', $events[0]->actor->id);
    }
}
