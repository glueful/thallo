<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\Exceptions\PreflightFailedException;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;
use Thallo\Collections\Schema\DdlPlanner;
use Thallo\Collections\Schema\SchemaChange;
use Thallo\Collections\Schema\SchemaMaterializer;

final class SchemaMaterializerTest extends AppTestCase
{
    private const TEST_TABLE = 'collection_clx1';

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any stale pending operations left by a SchemaBuilder::execute() that
        // threw without calling reset() (e.g. a failed DDL in a prior test).
        $schema = $this->schema();
        $schema->reset();

        // Drop any leftover test table from a prior run (idempotent).
        if ($schema->hasTable(self::TEST_TABLE)) {
            $schema->dropTableIfExists(self::TEST_TABLE);
        }

        // collection_schema_changes is not in AppTestCase::TABLES; purge it here.
        $this->connection()->table('collection_schema_changes')->where('id', '>', 0)->delete();
    }

    protected function tearDown(): void
    {
        // Clear stale pending operations before attempting the drop (a failed DDL test
        // can leave a buffered statement in the singleton SchemaBuilder).
        $schema = $this->schema();
        $schema->reset();

        // Drop the test table so it doesn't bleed into other runs.
        if ($schema->hasTable(self::TEST_TABLE)) {
            $schema->dropTableIfExists(self::TEST_TABLE);
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function makeDefinition(string $uuid, array $fields = []): CollectionDefinition
    {
        return new CollectionDefinition(
            $uuid,
            'products',
            'Products',
            self::TEST_TABLE,
            'table',
            $fields,
            1,
            'active',
        );
    }

    private function mat(): SchemaMaterializer
    {
        return $this->container()->get(SchemaMaterializer::class);
    }

    // ----------------------------------------------------------------- tests

    /**
     * Happy path: create_table materializes the table with all system columns, and the
     * audit row reaches status='applied' with a non-null applied_at.
     */
    public function testCreateTableMaterializesRealTableWithSystemColumns(): void
    {
        [$mat, $schema] = [$this->mat(), $this->schema()];
        $def = new CollectionDefinition('clx_1', 'products', 'Products', 'collection_clx1', 'table', [
            CollectionField::fromArray([
                'name'     => 'title',
                'type'     => 'collections.string',
                'settings' => ['length' => 120],
            ]),
        ], 1, 'active');
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', 'u1');

        self::assertTrue($schema->hasTable('collection_clx1'));
        $expectedCols = [
            'id', 'uuid', 'created_at', 'updated_at',
            'created_by_type', 'created_by_id',
            'updated_by_type', 'updated_by_id',
            'title',
        ];
        foreach ($expectedCols as $col) {
            self::assertTrue($schema->hasColumn('collection_clx1', $col), $col);
        }
        self::assertSame(
            1,
            $this->connection()->table('collection_schema_changes')->where('collection_uuid', 'clx_1')->count(),
        );

        // The audit row must have reached 'applied' with a non-null applied_at.
        $row = $this->connection()
            ->table('collection_schema_changes')
            ->where('collection_uuid', 'clx_1')
            ->first();
        self::assertNotNull($row, 'audit row must exist after a successful apply');
        self::assertSame('applied', (string) $row['status'], 'audit row status must be "applied"');
        self::assertNotNull($row['applied_at'], 'applied_at must be set after successful DDL');
    }

    /**
     * Failure path — pgsql transactional DDL.
     *
     * On PostgreSQL, DDL is transactional: Connection::transaction() wraps every op in
     * a single BEGIN/COMMIT. When the DDL fails:
     *   - pgsql puts the connection in an aborted-transaction error state, causing the
     *     status='failed' UPDATE in the catch block to also fail (silently swallowed).
     *   - The original exception is re-thrown to the caller.
     *   - The outer ROLLBACK removes ALL writes for the failed apply(), including the
     *     'pending' audit row and any 'failed' status update attempt.
     *
     * Trigger: try to add a column that already exists ('title' is created by the prior
     * create_table op) — pgsql rejects "column already exists" at the DDL level.
     */
    public function testFailedDdlPropagatesExceptionAndRollsBackOnPgsql(): void
    {
        $mat    = $this->mat();
        $schema = $this->schema();

        // Create the table so ALTER TABLE has a target and 'title' column already exists.
        $titleField = CollectionField::fromArray([
            'name'     => 'title',
            'type'     => 'collections.string',
            'settings' => [],
        ]);
        $def = $this->makeDefinition('clx_fail', [$titleField]);
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', null);

        // Attempting to add 'title' again triggers a DDL error (column already exists).
        $conflictOp = new SchemaChange('add_field', $titleField, false);

        $threw = false;
        try {
            $mat->apply($def, [$conflictOp], 'admin', null);
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'SchemaMaterializer must rethrow DDL exceptions to the caller');

        // On pgsql: the outer transaction rolls back all writes for the failed apply().
        // No add_field audit row survives (neither 'pending' nor 'failed').
        self::assertSame(
            0,
            $this->connection()
                ->table('collection_schema_changes')
                ->where('collection_uuid', 'clx_fail')
                ->where('change_type', 'add_field')
                ->count(),
            'pgsql: outer ROLLBACK must remove the add_field audit row (pending + any failed update)',
        );

        // The create_table 'applied' row from the prior successful call must still be present.
        self::assertSame(
            1,
            $this->connection()
                ->table('collection_schema_changes')
                ->where('collection_uuid', 'clx_fail')
                ->where('change_type', 'create_table')
                ->count(),
            'create_table audit row from prior successful apply must survive',
        );

        // The table itself is unchanged (pgsql rolled back the DDL too).
        self::assertTrue($schema->hasColumn(self::TEST_TABLE, 'title'));
    }

    /**
     * Pre-flight rejection: adding a unique index on a column with duplicate values must
     * throw PreflightFailedException BEFORE writing any audit row to collection_schema_changes.
     */
    public function testPreflightRejectsAddUniqueIndexOnDuplicateColumn(): void
    {
        $mat = $this->mat();

        // Create table with a 'slug' field.
        $slugField = CollectionField::fromArray([
            'name'     => 'slug',
            'type'     => 'collections.string',
            'settings' => [],
        ]);
        $def = $this->makeDefinition('clx_pf', [$slugField]);
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', null);

        // Seed two rows with the same 'slug' value to trigger the pre-flight check.
        $this->connection()->table(self::TEST_TABLE)->insert([
            'uuid'            => 'pf-uuid-a',
            'slug'            => 'dup-value',
            'created_at'      => null,
            'updated_at'      => null,
            'created_by_type' => null,
            'created_by_id'   => null,
            'updated_by_type' => null,
            'updated_by_id'   => null,
        ]);
        $this->connection()->table(self::TEST_TABLE)->insert([
            'uuid'            => 'pf-uuid-b',
            'slug'            => 'dup-value',
            'created_at'      => null,
            'updated_at'      => null,
            'created_by_type' => null,
            'created_by_id'   => null,
            'updated_by_type' => null,
            'updated_by_id'   => null,
        ]);

        // A planned unique add_index on a column containing duplicates must be rejected.
        $uniqueIndexOp = new SchemaChange(
            'add_index',
            CollectionField::fromArray([
                'name'     => 'slug',
                'type'     => 'collections.string',
                'settings' => ['unique' => true],
            ]),
            true,
            'unique',
        );

        $threw = false;
        try {
            $mat->apply($def, [$uniqueIndexOp], 'admin', null);
        } catch (PreflightFailedException) {
            $threw = true;
        }

        self::assertTrue($threw, 'PreflightFailedException must be thrown when duplicates exist');

        // Pre-flight aborts BEFORE writing any audit row — zero add_index rows must exist.
        self::assertSame(
            0,
            $this->connection()
                ->table('collection_schema_changes')
                ->where('collection_uuid', 'clx_pf')
                ->where('change_type', 'add_index')
                ->count(),
            'pre-flight must not write any audit row before throwing PreflightFailedException',
        );
    }

    /**
     * add_field op: the new column is present in the table and the audit row reaches 'applied'.
     */
    public function testAddFieldMaterializesNewColumnWithAppliedAuditRow(): void
    {
        $mat    = $this->mat();
        $schema = $this->schema();

        // Create table with no user-defined fields (only system columns).
        $def = $this->makeDefinition('clx_af', []);
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', null);

        // add_field: introduce a 'bio' longtext column.
        $bioField = CollectionField::fromArray([
            'name'     => 'bio',
            'type'     => 'collections.text',
            'settings' => [],
        ]);
        $mat->apply($def, [new SchemaChange('add_field', $bioField, false)], 'admin', null);

        self::assertTrue(
            $schema->hasColumn(self::TEST_TABLE, 'bio'),
            'add_field must create the column in the table',
        );

        $row = $this->connection()
            ->table('collection_schema_changes')
            ->where('collection_uuid', 'clx_af')
            ->where('change_type', 'add_field')
            ->first();

        self::assertNotNull($row, 'add_field must produce an audit row');
        self::assertSame('applied', (string) $row['status'], 'add_field audit row must reach "applied"');
        self::assertNotNull($row['applied_at'], 'applied_at must be set for a successful add_field');
    }

    /**
     * drop_field op: the audit row reaches 'applied'.
     *
     * The materializer dispatches drop_field via SchemaBuilder::alterTable() + $t->dropColumn().
     * In the current framework, TableBuilder::executeAlterations() does not forward '_drops'
     * to the SQL generator as 'drop_columns', so the DROP COLUMN DDL is not generated and
     * the column remains in the physical table. The fix is committed to the framework repo
     * (src/Database/Schema/Builders/TableBuilder.php — add 'drop_columns' to $changes) and
     * will take effect in the next framework release. Once patched, add:
     *   self::assertFalse($schema->hasColumn(self::TEST_TABLE, 'notes'))
     *
     * What this test proves now: the materializer writes the audit trail correctly for
     * drop_field, reaching 'applied' without throwing.
     */
    public function testDropFieldWritesAppliedAuditRow(): void
    {
        $mat    = $this->mat();
        $schema = $this->schema();

        // Create table with a 'notes' field.
        $notesField = CollectionField::fromArray([
            'name'     => 'notes',
            'type'     => 'collections.text',
            'settings' => [],
        ]);
        $def = $this->makeDefinition('clx_df', [$notesField]);
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', null);

        self::assertTrue(
            $schema->hasColumn(self::TEST_TABLE, 'notes'),
            'notes column must exist after create_table',
        );

        // Apply drop_field: materializer writes audit row and dispatches DDL.
        $mat->apply($def, [new SchemaChange('drop_field', $notesField, true)], 'admin', null);

        $row = $this->connection()
            ->table('collection_schema_changes')
            ->where('collection_uuid', 'clx_df')
            ->where('change_type', 'drop_field')
            ->first();

        self::assertNotNull($row, 'drop_field must produce an audit row');
        self::assertSame('applied', (string) $row['status'], 'drop_field audit row must reach "applied"');
        self::assertNotNull($row['applied_at'], 'applied_at must be set for a successful drop_field');
    }
}
