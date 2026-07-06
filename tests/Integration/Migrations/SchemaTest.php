<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\AppTestCase;

final class SchemaTest extends AppTestCase
{
    /** @dataProvider tables */
    public function testTableExists(string $table): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasTable($table),
            "expected table {$table} to exist after migration"
        );
    }

    /** @return array<int, array{0:string}> */
    public static function tables(): array
    {
        return array_map(
            static fn(string $t): array => [$t],
            [
                'content_types', 'entries', 'entry_drafts', 'entry_versions',
                'entry_publications', 'entry_routes', 'entry_redirects', 'entry_schema_migrations',
                'entry_references',
            ]
        );
    }

    public function testEntryRedirectsCarryTargetAndStatusGuards(): void
    {
        $checks = $this->connection()->getPDO()->query(
            "select conname from pg_constraint where conrelid = 'entry_redirects'::regclass"
        );
        self::assertNotFalse($checks);

        $names = array_map(
            static fn (array $row): string => (string) $row['conname'],
            $checks->fetchAll(\PDO::FETCH_ASSOC)
        );

        self::assertContains('chk_entry_redirect_status', $names);
        self::assertContains('chk_entry_redirect_origin', $names);
        self::assertContains('chk_entry_redirect_exactly_one_target', $names);
    }

    public function testEntrySchemaMigrationsCarryStatusGuardAndActiveUniqueIndex(): void
    {
        $checks = $this->connection()->getPDO()->query(
            "select conname from pg_constraint where conrelid = 'entry_schema_migrations'::regclass"
        );
        self::assertNotFalse($checks);

        $names = array_map(
            static fn (array $row): string => (string) $row['conname'],
            $checks->fetchAll(\PDO::FETCH_ASSOC)
        );

        self::assertContains('chk_entry_schema_migration_status', $names);

        $indexes = $this->connection()->getPDO()->query(
            "select indexname from pg_indexes where tablename = 'entry_schema_migrations'"
        );
        self::assertNotFalse($indexes);

        $indexNames = array_map(
            static fn (array $row): string => (string) $row['indexname'],
            $indexes->fetchAll(\PDO::FETCH_ASSOC)
        );

        self::assertContains('uniq_entry_schema_migrations_active', $indexNames);
    }

    public function testEntryRedirectsUniquenessIsScopedByTypeLocaleSource(): void
    {
        $db = $this->connection();

        $db->table('entry_redirects')->insert([
            'uuid' => 'rdaaaaaaaaaa',
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => 'type00000001',
            'target_locale' => 'en',
            'target_entry_uuid' => 'entry0000001',
            'status' => 301,
            'origin' => 'auto',
        ]);

        $db->table('entry_redirects')->insert([
            'uuid' => 'rdbbbbbbbbbb',
            'content_type_uuid' => 'type00000001',
            'locale' => 'fr',
            'source_slug' => 'old',
            'target_content_type_uuid' => 'type00000001',
            'target_locale' => 'fr',
            'target_entry_uuid' => 'entry0000001',
            'status' => 301,
            'origin' => 'auto',
        ]);

        $this->expectException(\Throwable::class);

        $db->table('entry_redirects')->insert([
            'uuid' => 'rdcccccccccc',
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => 'type00000001',
            'target_locale' => 'en',
            'target_entry_uuid' => 'entry0000001',
            'status' => 302,
            'origin' => 'manual',
        ]);
    }

    public function testFieldsColumnIsJsonb(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'entry_versions')
            ->where('column_name', '=', 'fields')
            ->first();
        self::assertSame('jsonb', $col['data_type']);
    }

    public function testContentTypesCarryOptionalDeliveryCacheTtl(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'content_types')
            ->where('column_name', '=', 'cache_ttl')
            ->first();

        self::assertNotNull($col, 'content_types.cache_ttl stores per-type delivery max-age overrides');
        self::assertSame('integer', $col['data_type']);
        self::assertSame('YES', $col['is_nullable']);
    }

    public function testContentTypesCarryPublicDeliveryOptIn(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'content_types')
            ->where('column_name', '=', 'public_delivery')
            ->first();

        self::assertNotNull($col, 'content_types.public_delivery opts a type into anonymous delivery reads');
        self::assertSame('boolean', $col['data_type']);
    }
}
