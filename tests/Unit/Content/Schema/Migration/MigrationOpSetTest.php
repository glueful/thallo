<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Schema\Migration;

use Thallo\Core\Content\Schema\Migration\DeleteField;
use Thallo\Core\Content\Schema\Migration\MigrationCollisionException;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Thallo\Core\Content\Schema\Migration\RenameField;
use PHPUnit\Framework\TestCase;

final class MigrationOpSetTest extends TestCase
{
    public function testRenameAndDeleteTransformFieldsInOrder(): void
    {
        $ops = new MigrationOpSet([
            new RenameField('title', 'heading'),
            new DeleteField('legacy'),
        ]);

        self::assertSame(
            ['body' => 'Copy', 'heading' => 'Hello'],
            $ops->apply(['title' => 'Hello', 'body' => 'Copy', 'legacy' => true])
        );
    }

    public function testRenameThrowsOnMaterializeCollision(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);

        $this->expectException(MigrationCollisionException::class);

        $ops->apply(['title' => 'Hello', 'heading' => 'Existing']);
    }

    public function testProjectionKeepsExistingTargetAndDropsOldSource(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);

        self::assertSame(
            ['heading' => 'Existing'],
            $ops->applyForProjection(['title' => 'Hello', 'heading' => 'Existing'])
        );
    }

    public function testSerializesAndRehydratesOps(): void
    {
        $ops = new MigrationOpSet([
            new RenameField('title', 'heading'),
            new DeleteField('legacy'),
        ]);

        $rehydrated = MigrationOpSet::fromArray($ops->toArray());

        self::assertSame(
            ['heading' => 'Hello'],
            $rehydrated->apply(['title' => 'Hello', 'legacy' => true])
        );
    }

    public function testUnknownSerializedOpFailsLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationOpSet::fromArray([['op' => 'retype', 'name' => 'title']]);
    }
}
