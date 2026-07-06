<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Indexing;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;

/**
 * Verifies that reconcile() drops a stale btree registry row and builds a GIN expression index
 * when a field's family flips from scalar (e.g. string) to membership (reference/asset).
 */
final class MembershipIndexReconcileTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the registry table — it is outside AppTestCase's TABLES truncate set.
        $this->connection()->table('lemma_filter_indexes')->where('id', '>', 0)->delete();
    }

    protected function tearDown(): void
    {
        // Drop any physical indexes created by reconcile() so reruns are clean.
        $rows = $this->connection()->table('lemma_filter_indexes')->select(['index_name'])->get();
        foreach ($rows as $row) {
            $name = (string) $row['index_name'];
            if (preg_match('/\A[a-z0-9_]+\z/', $name) === 1) {
                $this->connection()->getPDO()->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
        // Also clean up the index that may have been created in the first reconcile run
        // before the registry row was updated (stale btree drop happened, new GIN created).
        $this->connection()->table('lemma_filter_indexes')->where('id', '>', 0)->delete();
        parent::tearDown();
    }

    public function testStaleScalarRegistryRebuildAsGin(): void
    {
        $types = new ContentTypeRepository($this->connection());

        // 1. Seed a content type with category as a non-filterable reference field.
        $typeUuid = $types->create([
            'slug'   => 'article',
            'name'   => 'Article',
            'schema' => [
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category'],
            ],
        ]);

        // 2. Determine the stable index name (matches FilterIndexPlanner's hash).
        $indexName = 'lemma_fidx_' . substr(sha1($typeUuid . 'category'), 0, 16);

        // 3. Manually insert a stale registry row simulating an old btree scalar index
        //    (filter_type='string', status='ready') that was created before the field
        //    became a filterable membership reference.
        $this->connection()->table('lemma_filter_indexes')->insert([
            'uuid'              => Utils::generateNanoID(12),
            'content_type_uuid' => $typeUuid,
            'field'             => 'category',
            'filter_type'       => 'string',
            'index_name'        => $indexName,
            'status'            => 'ready',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        // 4. Flip the stored schema: category becomes a filterable reference field.
        //    updateSchema() permits this — the type stays 'reference'; only filterable is added.
        $types->updateSchema($typeUuid, [
            ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                'multiple' => true, 'filterable' => true],
        ]);

        // 5. Run reconcile() — the stale row should be detected, old index dropped, GIN built.
        $db  = $this->connection();
        $job = new EnsureFilterIndexesJob(['content_type_uuid' => $typeUuid], $this->appContext());
        $job->reconcile($db, $types, $typeUuid);

        // 6a. Registry row must now reflect 'reference' family and be 'ready'.
        $reg = $db->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('field', '=', 'category')
            ->first();
        self::assertNotNull($reg, 'registry row must exist after reconcile');
        self::assertSame('reference', $reg['filter_type'], 'registry filter_type should be updated to reference');
        self::assertSame('ready', $reg['status'], 'registry status must be ready after GIN index creation');
        self::assertSame($indexName, $reg['index_name'], 'index name must be stable');

        // 6b. Physical index must be GIN.
        $indexDef = $db->getPDO()
            ->query("SELECT indexdef FROM pg_indexes WHERE indexname = '{$indexName}'")
            ->fetchColumn();
        self::assertNotFalse($indexDef, "index '{$indexName}' must exist in pg_indexes");
        self::assertStringContainsString('USING gin', (string) $indexDef, 'rebuilt index must use GIN access method');
    }
}
