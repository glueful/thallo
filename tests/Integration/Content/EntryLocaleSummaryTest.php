<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Http\DTOs\Responses\Entries\EntryLocaleScheduleData;
use App\Content\Repositories\EntryRepository;
use App\Tests\Support\AppTestCase;

final class EntryLocaleSummaryTest extends AppTestCase
{
    public function testLocaleSummaryIncludesScheduledBlock(): void
    {
        $db = $this->connection();
        $db->table('content_types')->insert([
            'uuid' => 'typeloc00001',
            'slug' => 'post',
            'name' => 'Post',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => false,
            'status' => 'active',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $db->table('entries')->insert([
            'uuid' => 'entloc000001',
            'content_type_uuid' => 'typeloc00001',
            'status' => 'active',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        // entry_drafts keys on an auto-increment `id` — there is NO `uuid` column.
        $db->table('entry_drafts')->insert([
            'entry_uuid' => 'entloc000001',
            'locale' => 'en',
            'fields' => '{}',
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => '2026-06-27 01:00:00',
        ]);
        $db->table('entry_schedules')->insert([
            'uuid' => 'schedloc0001',
            'entry_uuid' => 'entloc000001',
            'locale' => 'en',
            'action' => 'publish',
            'status' => 'pending',
            'run_at' => '2026-07-01 09:00:00',
            'created_at' => '2026-06-27 01:00:00',
            'updated_at' => '2026-06-27 01:00:00',
        ]);

        $repo = $this->container()->get(EntryRepository::class);
        $summary = $repo->localeSummary('entloc000001');

        self::assertCount(1, $summary);
        self::assertSame('en', $summary[0]['locale']);
        self::assertTrue($summary[0]['has_draft']);
        self::assertArrayHasKey('scheduled', $summary[0]);
        self::assertDataMatchesDtoShape($summary[0]['scheduled'], EntryLocaleScheduleData::class);
        self::assertNotNull($summary[0]['scheduled']['publish']);
        self::assertNull($summary[0]['scheduled']['unpublish']);
        self::assertNull($summary[0]['scheduled']['last_failure']);
    }
}
