<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use Thallo\Collections\Exceptions\BlockedSchemaChangeException;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;
use Thallo\Collections\Schema\DdlPlanner;
use PHPUnit\Framework\TestCase;

final class DdlPlannerTest extends TestCase
{
    // ------------------------------------------------------------------ helpers

    private static function field(string $name, string $type, array $settings = []): CollectionField
    {
        return new CollectionField($name, $type, $settings);
    }

    private static function text(array $settings = []): CollectionField
    {
        return new CollectionField('', 'collections.string', $settings);
    }

    private static function integer(array $settings = []): CollectionField
    {
        return new CollectionField('', 'collections.integer', $settings);
    }

    /**
     * Build a CollectionDefinition from a name-keyed field map.
     *
     * @param array<string, CollectionField> $fieldMap
     */
    private static function def(array $fieldMap): CollectionDefinition
    {
        $fields = [];
        foreach ($fieldMap as $name => $proto) {
            $fields[] = new CollectionField($name, $proto->type, $proto->settings);
        }

        return new CollectionDefinition(
            uuid: 'test-uuid',
            name: 'test',
            label: 'Test',
            tableName: 'lcol_test',
            storageMode: 'table',
            fields: $fields,
            schemaVersion: 1,
            status: 'draft',
        );
    }

    // ------------------------------------------------------------------ planCreate

    public function testPlanCreateEmitsOneCreateTableOp(): void
    {
        $p    = new DdlPlanner();
        $defn = self::def(['title' => self::text(), 'views' => self::integer()]);
        $ops  = $p->planCreate($defn);

        self::assertCount(1, $ops);
        self::assertSame('create_table', $ops[0]->op);
        self::assertFalse($ops[0]->destructive);
        self::assertNull($ops[0]->field);
    }

    // ------------------------------------------------------------------ planAlter: field add / drop

    public function testAddFieldAndDropFieldArePlanned(): void
    {
        $p = new DdlPlanner();
        $a = self::def(['title' => self::text()]);
        $b = self::def(['title' => self::text(), 'views' => self::integer()]);

        $ops = $p->planAlter($a, $b);
        self::assertSame(['add_field'], array_map(fn ($o) => $o->op, $ops));
        self::assertFalse($ops[0]->destructive);
        self::assertSame('views', $ops[0]->field?->name);

        $ops2 = $p->planAlter($b, $a);
        self::assertSame('drop_field', $ops2[0]->op);
        self::assertTrue($ops2[0]->destructive);
        self::assertSame('views', $ops2[0]->field?->name);
    }

    public function testDropFieldIsDestructive(): void
    {
        $p    = new DdlPlanner();
        $with = self::def(['title' => self::text(), 'slug' => self::text()]);
        $sans = self::def(['title' => self::text()]);

        $ops = $p->planAlter($with, $sans);

        self::assertCount(1, $ops);
        self::assertSame('drop_field', $ops[0]->op);
        self::assertTrue($ops[0]->destructive);
    }

    public function testNoChangesEmitsNoOps(): void
    {
        $p   = new DdlPlanner();
        $def = self::def(['title' => self::text(), 'views' => self::integer()]);

        self::assertSame([], $p->planAlter($def, $def));
    }

    // ------------------------------------------------------------------ planAlter: index changes

    public function testAddUniqueIndexIsPlanned(): void
    {
        $p       = new DdlPlanner();
        $without = self::def(['slug' => self::text()]);
        $with    = self::def(['slug' => self::text(['unique' => true])]);

        $ops = $p->planAlter($without, $with);

        self::assertCount(1, $ops);
        self::assertSame('add_index', $ops[0]->op);
        self::assertSame('slug', $ops[0]->field?->name);
        self::assertTrue($ops[0]->destructive);
    }

    public function testDropUniqueIndexIsPlanned(): void
    {
        $p    = new DdlPlanner();
        $with = self::def(['slug' => self::text(['unique' => true])]);
        $sans = self::def(['slug' => self::text()]);

        $ops = $p->planAlter($with, $sans);

        self::assertCount(1, $ops);
        self::assertSame('drop_index', $ops[0]->op);
        self::assertSame('slug', $ops[0]->field?->name);
        self::assertFalse($ops[0]->destructive);
    }

    public function testAddPlainIndexIsPlanned(): void
    {
        $p       = new DdlPlanner();
        $without = self::def(['slug' => self::text()]);
        $with    = self::def(['slug' => self::text(['index' => true])]);

        $ops = $p->planAlter($without, $with);

        self::assertCount(1, $ops);
        self::assertSame('add_index', $ops[0]->op);
    }

    public function testDropPlainIndexIsPlanned(): void
    {
        $p    = new DdlPlanner();
        $with = self::def(['slug' => self::text(['index' => true])]);
        $sans = self::def(['slug' => self::text()]);

        $ops = $p->planAlter($with, $sans);

        self::assertCount(1, $ops);
        self::assertSame('drop_index', $ops[0]->op);
    }

    // ------------------------------------------------------------------ planAlter: blocked ops

    public function testRetypeIsBlocked(): void
    {
        $p = new DdlPlanner();
        $this->expectException(BlockedSchemaChangeException::class);
        $p->planAlter(self::def(['n' => self::integer()]), self::def(['n' => self::text()]));
    }

    public function testRetypeExceptionMessageNamesTheField(): void
    {
        $p = new DdlPlanner();

        try {
            $p->planAlter(
                self::def(['score' => self::integer()]),
                self::def(['score' => self::text()]),
            );
            self::fail('Expected BlockedSchemaChangeException');
        } catch (BlockedSchemaChangeException $e) {
            self::assertStringContainsString('score', $e->getMessage());
            self::assertStringContainsString('collections.integer', $e->getMessage());
            self::assertStringContainsString('collections.string', $e->getMessage());
        }
    }

    // -------------------------------------------------- planAlter: drop+add of differing names is allowed

    public function testDropAndAddByDifferingNamesIsAllowed(): void
    {
        // "rename" via JSON is just drop old + add new; both field names differ → allowed
        $p     = new DdlPlanner();
        $older = self::def(['title' => self::text()]);
        $newer = self::def(['headline' => self::text()]);

        $ops = $p->planAlter($older, $newer);

        $opNames = array_map(fn ($o) => $o->op, $ops);
        self::assertContains('drop_field', $opNames);
        self::assertContains('add_field', $opNames);
    }

    // ----------------------------------------- planAlter: new field with unique — explicit add_index

    public function testAddingNewFieldWithUniqueEmitsAddFieldPlusUniqueIndexOp(): void
    {
        $p     = new DdlPlanner();
        $empty = self::def([]);
        $next  = self::def(['slug' => self::text(['unique' => true])]);

        $ops = $p->planAlter($empty, $next);

        // Indexes are never inline column modifiers: a separate add_index op with the
        // kind fixed at plan time follows the add_field (see planner docblock).
        self::assertSame(['add_field', 'add_index'], array_map(fn ($o) => $o->op, $ops));
        self::assertSame('unique', $ops[1]->indexKind);
        self::assertFalse($ops[1]->destructive, 'a brand-new column trivially passes the pre-flight');
    }

    public function testAddingNewFieldWithPlainIndexEmitsPlainIndexOp(): void
    {
        $p    = new DdlPlanner();
        $ops  = $p->planAlter(self::def([]), self::def(['status' => self::text(['index' => true])]));

        self::assertSame(['add_field', 'add_index'], array_map(fn ($o) => $o->op, $ops));
        self::assertSame('plain', $ops[1]->indexKind);
    }

    public function testUniqueAndIndexOnNewFieldEmitsOnlyTheUniqueOp(): void
    {
        // Effective-plain rule: a unique constraint already serves lookups, so the
        // redundant plain index is never planned.
        $p   = new DdlPlanner();
        $ops = $p->planAlter(self::def([]), self::def(['code' => self::text(['unique' => true, 'index' => true])]));

        self::assertSame(['add_field', 'add_index'], array_map(fn ($o) => $o->op, $ops));
        self::assertSame('unique', $ops[1]->indexKind);
    }

    public function testDroppingUniquePlansAUniqueKindDrop(): void
    {
        $p   = new DdlPlanner();
        $ops = $p->planAlter(
            self::def(['sku' => self::text(['unique' => true])]),
            self::def(['sku' => self::text()]),
        );

        self::assertSame(['drop_index'], array_map(fn ($o) => $o->op, $ops));
        self::assertSame('unique', $ops[0]->indexKind);
    }

    // ----------------------------------------- planAlter: storage-signature blocks (v1 rule)

    public function testNullableFlipIsBlocked(): void
    {
        $p       = new DdlPlanner();
        $current = self::def(['body' => self::text(['nullable' => true])]);
        $next    = self::def(['body' => self::text(['nullable' => false])]);

        $this->expectException(BlockedSchemaChangeException::class);
        $this->expectExceptionMessageMatches('/body/');
        $p->planAlter($current, $next);
    }

    public function testLengthChangeIsBlocked(): void
    {
        $p       = new DdlPlanner();
        $current = self::def(['code' => self::text(['length' => 50])]);
        $next    = self::def(['code' => self::text(['length' => 100])]);

        $this->expectException(BlockedSchemaChangeException::class);
        $this->expectExceptionMessageMatches('/code/');
        $p->planAlter($current, $next);
    }

    public function testMultiFlipIsBlocked(): void
    {
        $p       = new DdlPlanner();
        $current = self::def(['tags' => self::field('tags', 'collections.relation', ['multi' => false])]);
        $next    = self::def(['tags' => self::field('tags', 'collections.relation', ['multi' => true])]);

        $this->expectException(BlockedSchemaChangeException::class);
        $this->expectExceptionMessageMatches('/tags/');
        $p->planAlter($current, $next);
    }

    public function testRelationTargetChangeIsBlocked(): void
    {
        $p       = new DdlPlanner();
        $current = self::def(['ref' => self::field('ref', 'collections.relation', ['target' => 'posts'])]);
        $next    = self::def(['ref' => self::field('ref', 'collections.relation', ['target' => 'pages'])]);

        $this->expectException(BlockedSchemaChangeException::class);
        $this->expectExceptionMessageMatches('/ref/');
        $p->planAlter($current, $next);
    }

    public function testRetypeRemainsBlocked(): void
    {
        // Confirm that type-change is still blocked under the generalised storage-signature rule.
        $p = new DdlPlanner();
        $this->expectException(BlockedSchemaChangeException::class);
        $p->planAlter(
            self::def(['count' => self::integer()]),
            self::def(['count' => self::text()]),
        );
    }

    // ----------------------------------------- planAlter: index-only change is NOT blocked

    public function testIndexOnlyChangeEmitsIndexOpWithoutException(): void
    {
        // Changing only `index` (storage unchanged) must emit an index op — no exception.
        $p       = new DdlPlanner();
        $without = self::def(['email' => self::text()]);
        $with    = self::def(['email' => self::text(['index' => true])]);

        $ops = $p->planAlter($without, $with);

        self::assertCount(1, $ops);
        self::assertSame('add_index', $ops[0]->op);
    }

    public function testUniqueOnlyChangeEmitsIndexOpWithoutException(): void
    {
        // Changing only `unique` (storage unchanged) must emit an index op — no exception.
        $p       = new DdlPlanner();
        $without = self::def(['slug' => self::text()]);
        $with    = self::def(['slug' => self::text(['unique' => true])]);

        // Must not throw — only emit add_index.
        $ops = $p->planAlter($without, $with);

        $opNames = array_map(fn ($o) => $o->op, $ops);
        self::assertContains('add_index', $opNames);
        self::assertNotContains('add_field', $opNames);
    }
}
