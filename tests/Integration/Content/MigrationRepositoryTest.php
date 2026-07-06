<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\AppTestCase;

final class MigrationRepositoryTest extends AppTestCase
{
    public function testSchemaMigrationTableExistsWithStatusGuard(): void
    {
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable('entry_schema_migrations'));

        $checks = $this->connection()->getPDO()->query(
            "select conname from pg_constraint where conrelid = 'entry_schema_migrations'::regclass"
        );
        self::assertNotFalse($checks);

        $names = array_map(
            static fn (array $row): string => (string) $row['conname'],
            $checks->fetchAll(\PDO::FETCH_ASSOC)
        );

        self::assertContains('chk_entry_schema_migration_status', $names);
    }

    public function testRecordAndFlipStoresMigrationAndAdvancesContentTypeSchema(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);

        $uuid = $this->repo()->recordAndFlip(
            $type,
            1,
            $ops,
            [['name' => 'heading', 'type' => 'string']],
            3,
            'user00000001',
        );

        $row = $this->repo()->find($uuid);
        self::assertNotNull($row);
        self::assertSame($type, $row['content_type_uuid']);
        self::assertSame(1, $row['from_version']);
        self::assertSame(2, $row['to_version']);
        self::assertSame('running', $row['status']);
        self::assertEquals($ops->toArray(), $row['ops']);
        self::assertSame(3, $row['work_items_total']);

        $typeRow = $this->types()->findByUuid($type);
        self::assertSame(2, $typeRow['schema_version']);
        self::assertSame('heading', $typeRow['schema'][0]['name']);
    }

    public function testOnlyOneActiveMigrationPerContentType(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        $this->repo()->recordAndFlip($type, 1, $ops, [['name' => 'heading', 'type' => 'string']], 0, null);

        $this->expectException(\Throwable::class);

        $this->repo()->recordAndFlip($type, 2, $ops, [['name' => 'headline', 'type' => 'string']], 0, null);
    }

    public function testFailureCountersResetAndFinish(): void
    {
        $type = $this->types()->create(['slug' => 'article', 'name' => 'Article', 'schema' => []]);
        $uuid = $this->repo()->recordAndFlip($type, 1, new MigrationOpSet([new RenameField('a', 'b')]), [], 1, null);

        $this->repo()->incrementDone($uuid);
        $this->repo()->recordFailure($uuid, 'entry0000001', 'en', 'draft', 'collision');

        $failed = $this->repo()->find($uuid);
        self::assertSame(1, $failed['work_items_done']);
        self::assertSame(1, $failed['work_items_failed']);
        self::assertSame('collision', $failed['failure_report'][0]['reason']);

        $this->repo()->resetFailures($uuid);
        $reset = $this->repo()->find($uuid);
        self::assertSame(1, $reset['work_items_done']);
        self::assertSame(0, $reset['work_items_failed']);
        self::assertSame([], $reset['failure_report']);

        $this->repo()->finish($uuid, 'completed');
        self::assertSame('completed', $this->repo()->find($uuid)['status']);
    }

    public function testChainForReturnsOrderedMigrationsAfterVersion(): void
    {
        $type = $this->types()->create(['slug' => 'article', 'name' => 'Article', 'schema' => []]);
        $first = $this->repo()->recordAndFlip($type, 1, new MigrationOpSet([new RenameField('a', 'b')]), [], 0, null);
        $this->repo()->finish($first, 'completed');
        $second = $this->repo()->recordAndFlip($type, 2, new MigrationOpSet([new RenameField('b', 'c')]), [], 0, null);

        self::assertSame([$second], array_column($this->repo()->chainFor($type, 2), 'uuid'));
    }

    private function repo(): MigrationRepository
    {
        return new MigrationRepository($this->connection());
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }
}
