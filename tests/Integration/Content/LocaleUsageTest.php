<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\EntryRepository;
use App\Tests\Support\AppTestCase;

final class LocaleUsageTest extends AppTestCase
{
    public function testLocaleUsageCountsDraftsAndPublications(): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'entuse000001',
            'content_type_uuid' => 'typeuse00001',
            'status' => 'active',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        // entry_drafts keys on auto-increment `id` — no `uuid` column.
        $db->table('entry_drafts')->insert([
            'entry_uuid' => 'entuse000001',
            'locale' => 'fr',
            'fields' => '{}',
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => '2026-06-27 01:00:00',
        ]);
        // entry_publications keys on auto-increment `id` (no `uuid`) and requires `version_uuid`.
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'entuse000001',
            'locale' => 'fr',
            'version_uuid' => 'veruse000001',
            'published_at' => '2026-06-27 02:00:00',
        ]);

        // Soft-deleted entry in 'es': entries.status='deleted' but child rows remain.
        // localeUsage must exclude these orphaned rows from its count.
        $db->table('entries')->insert([
            'uuid' => 'entuse000002',
            'content_type_uuid' => 'typeuse00001',
            'status' => 'deleted',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $db->table('entry_drafts')->insert([
            'entry_uuid' => 'entuse000002',
            'locale' => 'es',
            'fields' => '{}',
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => '2026-06-27 01:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'entuse000002',
            'locale' => 'es',
            'version_uuid' => 'veruse000002',
            'published_at' => '2026-06-27 02:00:00',
        ]);

        $repo = $this->container()->get(EntryRepository::class);

        self::assertSame(['published_entries' => 1, 'draft_entries' => 1], $repo->localeUsage('fr'));
        self::assertSame(['published_entries' => 0, 'draft_entries' => 0], $repo->localeUsage('de'));
        // Soft-deleted entry's child rows must not inflate the count.
        self::assertSame(['published_entries' => 0, 'draft_entries' => 0], $repo->localeUsage('es'));
    }
}
