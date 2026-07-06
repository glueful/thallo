<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Seo\RedirectRepository;
use App\Tests\Support\AppTestCase;

final class RedirectRepositoryTest extends AppTestCase
{
    private function repo(): RedirectRepository
    {
        return new RedirectRepository($this->connection());
    }

    public function testCreateInternalRedirectAndLookupBySource(): void
    {
        $uuid = $this->repo()->create([
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'old',
            'target_content_type_uuid' => 'type00000002',
            'target_locale' => 'fr',
            'target_entry_uuid' => 'entry0000001',
            'status' => 308,
            'origin' => 'manual',
            'created_by' => 'user00000001',
        ]);

        $row = $this->repo()->findBySource('type00000001', 'en', 'old');

        self::assertSame($uuid, $row['uuid']);
        self::assertSame('type00000002', $row['target_content_type_uuid']);
        self::assertSame('fr', $row['target_locale']);
        self::assertSame('entry0000001', $row['target_entry_uuid']);
        self::assertNull($row['target_url']);
        self::assertSame(308, $row['status']);
    }

    public function testCreateExternalRedirectAndListByType(): void
    {
        $this->repo()->create([
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'external',
            'target_url' => 'https://example.com/new',
            'status' => 302,
            'origin' => 'manual',
        ]);

        $rows = $this->repo()->listForType('type00000001');

        self::assertCount(1, $rows);
        self::assertSame('external', $rows[0]['source_slug']);
        self::assertSame('https://example.com/new', $rows[0]['target_url']);
        self::assertSame(302, $rows[0]['status']);
    }

    public function testDeleteBySourceRemovesRedirect(): void
    {
        $this->repo()->create([
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'old',
            'target_url' => '/new',
            'status' => 301,
            'origin' => 'manual',
        ]);

        $this->repo()->deleteBySource('type00000001', 'en', 'old');

        self::assertNull($this->repo()->findBySource('type00000001', 'en', 'old'));
    }

    public function testUpsertAutoRedirectReplacesSameSource(): void
    {
        $first = $this->repo()->upsertAuto(
            'type00000001',
            'en',
            'old',
            'type00000001',
            'en',
            'entry0000001'
        );
        $second = $this->repo()->upsertAuto(
            'type00000001',
            'en',
            'old',
            'type00000002',
            'fr',
            'entry0000002'
        );

        $row = $this->repo()->findBySource('type00000001', 'en', 'old');

        self::assertSame($first, $second);
        self::assertSame('type00000002', $row['target_content_type_uuid']);
        self::assertSame('fr', $row['target_locale']);
        self::assertSame('entry0000002', $row['target_entry_uuid']);
        self::assertSame('auto', $row['origin']);
        self::assertSame(301, $row['status']);
    }

    public function testFindByUuidAndDeleteByUuidAreEntryScoped(): void
    {
        $uuid = $this->repo()->create([
            'content_type_uuid' => 'type00000001',
            'locale' => 'en',
            'source_slug' => 'old',
            'target_url' => '/new',
            'status' => 301,
            'origin' => 'manual',
        ]);

        self::assertNotNull($this->repo()->findByUuid($uuid));
        self::assertFalse($this->repo()->deleteByUuid($uuid, 'other0000001'));
        self::assertTrue($this->repo()->deleteByUuid($uuid, 'type00000001'));
        self::assertNull($this->repo()->findByUuid($uuid));
    }
}
