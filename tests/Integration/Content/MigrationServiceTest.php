<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Jobs\RunBackfillJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\SchemaParseException;
use App\Content\Services\ActiveMigrationException;
use App\Content\Services\MigrationService;
use App\Tests\Support\AppTestCase;

final class MigrationServiceTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connection()->getSchemaBuilder()->hasTable('queue_jobs')) {
            $this->connection()->table('queue_jobs')->where('id', '>', 0)->delete();
        }
    }

    public function testMigrateValidOpsFlipsSchemaRecordsRowAndQueuesBackfill(): void
    {
        $type = $this->createType();

        $uuid = $this->service()->migrate(
            $type,
            [['op' => 'rename', 'from' => 'title', 'to' => 'heading']],
            'user00000001'
        );

        $row = $this->migrations()->find($uuid);
        self::assertNotNull($row);
        self::assertSame('running', $row['status']);
        self::assertEquals([['op' => 'rename', 'from' => 'title', 'to' => 'heading']], $row['ops']);
        $typeRow = $this->types()->findByUuid($type);
        self::assertSame(2, $typeRow['schema_version']);
        self::assertSame('heading', $typeRow['schema'][0]['name']);
        self::assertTrue($this->queueContains(RunBackfillJob::class, $uuid));
    }

    public function testInvalidOpsAreRejectedWithoutQueueing(): void
    {
        $cases = [
            [['op' => 'delete', 'name' => 'missing']],
            [['op' => 'rename', 'from' => 'missing', 'to' => 'heading']],
            [['op' => 'rename', 'from' => 'title', 'to' => 'body']],
            [
                ['op' => 'rename', 'from' => 'title', 'to' => 'heading'],
                ['op' => 'rename', 'from' => 'body', 'to' => 'heading'],
            ],
            [
                ['op' => 'delete', 'name' => 'title'],
                ['op' => 'rename', 'from' => 'title', 'to' => 'heading'],
            ],
            [
                ['op' => 'rename', 'from' => 'title', 'to' => 'heading'],
                ['op' => 'rename', 'from' => 'title', 'to' => 'headline'],
            ],
            [],
        ];

        foreach ($cases as $ops) {
            $this->connection()->table('queue_jobs')->where('id', '>', 0)->delete();
            $type = $this->createType('article' . substr(md5(json_encode($ops)), 0, 8));

            try {
                $this->service()->migrate($type, $ops, null);
                self::fail('Expected invalid migration ops to throw.');
            } catch (SchemaParseException) {
                self::assertSame(0, (int) $this->connection()->table('queue_jobs')->count());
                self::assertNull($this->migrations()->activeForType($type));
            }
        }
    }

    public function testSecondActiveMigrationIsRejectedWithoutQueueing(): void
    {
        $type = $this->createType();
        $this->service()->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
        $this->connection()->table('queue_jobs')->where('id', '>', 0)->delete();

        $this->expectException(ActiveMigrationException::class);

        try {
            $this->service()->migrate($type, [['op' => 'rename', 'from' => 'body', 'to' => 'copy']], null);
        } finally {
            self::assertSame(0, (int) $this->connection()->table('queue_jobs')->count());
        }
    }

    private function service(): MigrationService
    {
        return $this->container()->get(MigrationService::class);
    }

    private function migrations(): MigrationRepository
    {
        return new MigrationRepository($this->connection());
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    private function createType(string $slug = 'article'): string
    {
        return $this->types()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'text'],
            ],
        ]);
    }

    private function queueContains(string $jobClass, string $migrationUuid): bool
    {
        foreach ($this->connection()->table('queue_jobs')->get() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (
                is_array($payload)
                && ($payload['job'] ?? null) === $jobClass
                && ($payload['data']['migration_uuid'] ?? null) === $migrationUuid
            ) {
                return true;
            }
        }

        return false;
    }
}
