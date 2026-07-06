<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Exceptions\RowValidationException;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * A relation field with target "users" references the framework users table. This asserts the key
 * behaviour change: a `users` target is now enforced (a non-existent user uuid is rejected) rather
 * than silently skipped as a non-collection target was before.
 */
final class UserRelationTest extends AppTestCase
{
    private const COLLECTION = 'rel_user_posts';

    private CollectionDefinition $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();

        $this->posts = $this->manager()->create([
            'name' => self::COLLECTION,
            'label' => 'Rel User Posts',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
                ['name' => 'owner', 'type' => 'collections.relation', 'settings' => [
                    'nullable' => true,
                    'target' => 'users',
                    'multi' => false,
                ]],
            ],
        ], 'admin', 'u1');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testRelationToUsersRejectsNonExistentUser(): void
    {
        $caught = null;
        try {
            $this->repo()->create(
                $this->posts,
                ['title' => 'X', 'owner' => 'no-such-user'],
                $this->actor(),
            );
        } catch (RowValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull(
            $caught,
            'a users-target relation must validate existence (a non-existent user uuid is rejected)',
        );
        self::assertArrayHasKey('owner', $caught->errors());
    }

    public function testNullableUsersRelationAllowsOmittedValue(): void
    {
        $row = $this->repo()->create($this->posts, ['title' => 'No owner'], $this->actor());

        self::assertNotEmpty($row['uuid']);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function repo(): RowRepository
    {
        return $this->container()->get(RowRepository::class);
    }

    private function actor(): Actor
    {
        return new Actor('admin', 'u1');
    }

    private function cleanup(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $table = CollectionManager::tableNameFor(self::COLLECTION);
        if ($schema->hasTable($table)) {
            $schema->dropTableIfExists($table);
        }
        $this->connection()->table('collection_definitions')->where('name', self::COLLECTION)->delete();
    }
}
