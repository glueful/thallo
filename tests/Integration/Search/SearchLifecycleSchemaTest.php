<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Schema\SearchSchemaVerifier;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * The index lifecycle's schema (search block spec §3.3, §3.5.1): documents gain kind, source id,
 * subtype, display meta and generation; four workspace-owned tables hold the state, the journal,
 * the per-target acknowledgements and the rebuild demand.
 */
final class SearchLifecycleSchemaTest extends AppTestCase
{
    private const TABLES = ['search_index_state', 'search_index_changes', 'search_index_acks', 'search_index_demand'];

    public function testTheColumnsAndTablesExist(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        foreach (['kind', 'source_id', 'subtype', 'meta', 'generation'] as $column) {
            self::assertTrue($schema->hasColumn('search_documents', $column), "search_documents.{$column}");
        }
        foreach (self::TABLES as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        foreach (['retired_targets', 'journal_head', 'drainer_token', 'satisfied_seq'] as $column) {
            self::assertTrue($schema->hasColumn('search_index_state', $column), "search_index_state.{$column}");
        }
    }

    public function testTheNewTablesAreWorkspaceOwnedInstanceRows(): void
    {
        $all = ThalloTenantTables::all();
        $uniques = [
            'search_index_state' => ['tenant_uuid', 'kind'],
            'search_index_changes' => ['tenant_uuid', 'kind', 'seq'],
            'search_index_acks' => ['tenant_uuid', 'kind', 'entry_seq', 'target'],
            'search_index_demand' => ['tenant_uuid', 'kind', 'seq'],
        ];
        foreach ($uniques as $table => $columns) {
            self::assertArrayHasKey($table, $all);
            self::assertSame('instance', $all[$table]['kind']);
            self::assertSame($columns, $all[$table]['widened_uniques'][0][1]);
        }
    }

    public function testTheVerifierKnowsBothMigrations(): void
    {
        $verifier = new SearchSchemaVerifier();
        self::assertSame(
            ['001_CreateSearchDocumentsTable.php', '002_SearchIndexLifecycle.php'],
            $verifier->migrationBasenames(),
        );
        foreach ($verifier->migrationBasenames() as $name) {
            self::assertTrue($verifier->verify($this->connection(), $name), $name);
        }
        self::assertFalse($verifier->verify($this->connection(), '999_Unknown.php'));
    }

    public function testWidenedColumnsAcceptAProductRowOnPostgres(): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The widening runs on Postgres only.');
        }
        $this->connection()->table('search_documents')->insert([
            'doc_id' => 'products_' . str_repeat('A', 64) . '_A' . str_repeat('x', 30),
            'kind' => 'products', 'source_id' => 'Ab12', 'locale' => '*',
            'href' => '/shop/products/a', 'title' => 'A', 'body' => 'b', 'ts_config' => 'simple',
            'generation' => 1,
        ]);
        self::assertSame(1, $this->connection()->table('search_documents')->where('kind', '=', 'products')->count());
    }
}
