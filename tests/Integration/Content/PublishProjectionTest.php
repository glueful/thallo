<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Services\MigrationService;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;

/**
 * Regression: publishing a draft still on an OLDER schema_version (a backfill lagging or failed
 * behind a field rename) must project the draft up to the current shape BEFORE validating, so the
 * renamed field's data survives. FieldValidator keeps only keys the current schema declares, so
 * without the projection the pre-rename key is silently dropped (or fails a now-required rename
 * target), losing published data.
 */
final class PublishProjectionTest extends AppTestCase
{
    public function testPublishProjectsDraftOnOlderSchemaBeforeValidating(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        // Draft is authored against schema v1 ({title}).
        $entries->saveDraft($entry, 'en', ['title' => 'Hello'], 1, 0, 'user00000001');

        // Rename title -> heading. This bumps the type to schema_version 2 and records the migration,
        // but the backfill has NOT run, so the draft is still {title:'Hello'} at schema_version 1.
        $this->container()->get(MigrationService::class)
            ->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
        self::assertSame(1, (int) $entries->findDraft($entry, 'en')['schema_version']);
        self::assertSame(2, (int) $types->findByUuid($type)['schema_version']);

        $versionUuid = $this->service()->publish($entry, 'en', 'user00000001');

        // The renamed field's data survived, and the snapshot records the CURRENT schema version.
        $version = (new VersionRepository($this->connection()))->findVersionByUuid($versionUuid);
        self::assertSame(['heading' => 'Hello'], $version['fields']);
        self::assertSame(2, (int) $version['schema_version']);
    }

    public function testPublishLeavesCurrentSchemaDraftUntouched(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'Untouched'], 1, 0, 'user00000001');

        $versionUuid = $this->service()->publish($entry, 'en', 'user00000001');

        $version = (new VersionRepository($this->connection()))->findVersionByUuid($versionUuid);
        self::assertSame(['title' => 'Untouched'], $version['fields']);
        self::assertSame(1, (int) $version['schema_version']);
    }

    private function service(): PublishService
    {
        $types = new ContentTypeRepository($this->connection());

        return new PublishService(
            $this->appContext(),
            new EntryRepository($this->connection(), $this->appContext(), $types),
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
            null,
            new SchemaProjector(new MigrationRepository($this->connection()), $types),
        );
    }
}
