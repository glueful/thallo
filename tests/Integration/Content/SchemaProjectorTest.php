<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Content\Schema\Migration\SchemaProjector;
use App\Tests\Support\AppTestCase;

final class SchemaProjectorTest extends AppTestCase
{
    public function testNoOpsWhenStoredVersionIsCurrent(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);

        self::assertSame(
            ['title' => 'Hello'],
            $this->projector()->project($type, 1, ['title' => 'Hello'])
        );
    }

    public function testProjectsThroughMultipleMigrationsInVersionOrder(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);

        $first = $this->migrations()->recordAndFlip(
            $type,
            1,
            new MigrationOpSet([new RenameField('title', 'heading')]),
            [['name' => 'heading', 'type' => 'string']],
            0,
            null,
        );
        $this->migrations()->finish($first, 'completed');
        $this->migrations()->recordAndFlip(
            $type,
            2,
            new MigrationOpSet([new RenameField('heading', 'headline')]),
            [['name' => 'headline', 'type' => 'string']],
            0,
            null,
        );

        self::assertSame(
            ['headline' => 'Hello'],
            $this->projector()->project($type, 1, ['title' => 'Hello'])
        );
    }

    public function testProjectionKeepsAlreadyMaterializedTargetOnCollision(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $this->migrations()->recordAndFlip(
            $type,
            1,
            new MigrationOpSet([new RenameField('title', 'heading')]),
            [['name' => 'heading', 'type' => 'string']],
            0,
            null,
        );

        self::assertSame(
            ['heading' => 'Current'],
            $this->projector()->project($type, 1, ['title' => 'Old', 'heading' => 'Current'])
        );
    }

    public function testSameProjectorReflectsAMigrationAddedAfterFirstProject(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $projector = $this->projector();

        self::assertSame(['title' => 'Hello'], $projector->project($type, 1, ['title' => 'Hello']));

        $this->migrations()->recordAndFlip(
            $type,
            1,
            new MigrationOpSet([new RenameField('title', 'heading')]),
            [['name' => 'heading', 'type' => 'string']],
            0,
            null,
        );

        self::assertSame(['heading' => 'Hello'], $projector->project($type, 1, ['title' => 'Hello']));
    }

    private function projector(): SchemaProjector
    {
        return new SchemaProjector($this->migrations(), $this->types());
    }

    private function migrations(): MigrationRepository
    {
        return new MigrationRepository($this->connection());
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }
}
