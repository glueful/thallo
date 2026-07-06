<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\AppTestCase;

final class BlockMigrationRepositoryTest extends AppTestCase
{
    public function testRecordAndFlipLifecycleAndSuffixSelection(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);

        $before = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s.u');
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('title', 'heading')]),
            [['name' => 'heading', 'type' => 'string']],
            5,
            'user00000001',
        );

        // Flip happened atomically with the record.
        self::assertSame('heading', $blocks->findBySlug('card')['schema'][0]['name']);

        // Active while running AND while failed; only completed unlocks (spec §2/§3).
        self::assertNotNull($repo->activeForType($type));
        $repo->finish($uuid, 'failed');
        self::assertNotNull($repo->activeForType($type));
        self::assertNotSame([], $repo->activeAny());
        $repo->finish($uuid, 'completed');
        self::assertNull($repo->activeForType($type));
        self::assertSame([], $repo->activeAny());
        self::assertNotNull($repo->find($uuid)['completed_at']);

        // Timestamp-suffix selection: completed + strictly-after only, ASC.
        self::assertCount(1, $repo->completedAfter($type, $before));
        $after = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s.u');
        self::assertSame([], $repo->completedAfter($type, $after));
        // Strict >: equal timestamp applies nothing (spec §5).
        $row = $repo->find($uuid);
        self::assertSame([], $repo->completedAfter($type, (string) $row['created_at']));
    }

    public function testMicrosecondPrecisionIsPersisted(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'ms', 'label' => 'Ms', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('a', 'b')]),
            [['name' => 'b', 'type' => 'string']],
            0,
            null,
        );
        $created = (string) $repo->find($uuid)['created_at'];
        // Not second-truncated: fractional part present.
        self::assertMatchesRegularExpression('/\.\d{1,6}$/', $created);
    }

    public function testAccountingMirrorsContentTypeMigrations(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $type = $blocks->create(['slug' => 'acct', 'label' => 'A', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);
        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $uuid = $repo->recordAndFlip(
            $type,
            new MigrationOpSet([new RenameField('a', 'b')]),
            [['name' => 'b', 'type' => 'string']],
            2,
            null,
        );
        $repo->incrementDone($uuid);
        $repo->recordFailure($uuid, 'entry0000001', 'en', 'draft', 'boom');
        $row = $repo->find($uuid);
        self::assertSame(1, (int) $row['work_items_done']);
        self::assertSame(1, (int) $row['work_items_failed']);
        self::assertNotSame([], $row['failure_report']);
        $repo->resetFailures($uuid);
        self::assertSame(0, (int) $repo->find($uuid)['work_items_failed']);
    }
}
