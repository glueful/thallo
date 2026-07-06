<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\MigrationController;
use App\Content\Http\DTOs\MigrationData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class MigrationApiTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connection()->getSchemaBuilder()->hasTable('queue_jobs')) {
            $this->connection()->table('queue_jobs')->where('id', '>', 0)->delete();
        }
    }

    public function testPostMigrationFlipsSchemaAndReturnsRunningRow(): void
    {
        $type = $this->createType('article');

        $resp = $this->controller()->store(
            $this->hydrate([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]),
            new Request(),
            'article'
        );

        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getContent());
        $data = json_decode((string) $resp->getContent(), true)['data']['migration'];
        self::assertSame($type, $data['content_type_uuid']);
        self::assertSame('running', $data['status']);
        self::assertSame(2, $this->types()->findByUuid($type)['schema_version']);
    }

    public function testPostInvalidOpsReturns422(): void
    {
        $this->createType('article');

        $resp = $this->controller()->store(
            $this->hydrate([['op' => 'delete', 'name' => 'missing']]),
            new Request(),
            'article'
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame(0, (int) $this->connection()->table('queue_jobs')->count());
    }

    public function testPostSecondActiveReturns409(): void
    {
        $this->createType('article');
        $this->controller()->store(
            $this->hydrate([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]),
            new Request(),
            'article'
        );

        $resp = $this->controller()->store(
            $this->hydrate([['op' => 'rename', 'from' => 'body', 'to' => 'copy']]),
            new Request(),
            'article'
        );

        self::assertSame(409, $resp->getStatusCode());
    }

    public function testPostUnknownTypeReturns404(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate([['op' => 'delete', 'name' => 'title']]),
            new Request(),
            'missing'
        );

        self::assertSame(404, $resp->getStatusCode());
    }

    public function testGetMigrationReturnsPollableRowScopedToType(): void
    {
        $type = $this->createType('article');
        $other = $this->createType('page');
        $uuid = $this->startMigration('article');

        $resp = $this->controller()->show(new Request(), 'article', $uuid);

        self::assertSame(200, $resp->getStatusCode());
        $migration = json_decode((string) $resp->getContent(), true)['data']['migration'];
        self::assertSame($type, $migration['content_type_uuid']);
        self::assertArrayHasKey('failure_report', $migration);

        $otherUuid = $this->repo()->recordAndFlip(
            $other,
            1,
            new \App\Content\Schema\Migration\MigrationOpSet([
                new \App\Content\Schema\Migration\RenameField('title', 'heading'),
            ]),
            [['name' => 'heading', 'type' => 'string']],
            0,
            null
        );
        self::assertSame(404, $this->controller()->show(new Request(), 'article', $otherUuid)->getStatusCode());
    }

    public function testGetMigrationsListsForType(): void
    {
        $this->createType('article');
        $uuid = $this->startMigration('article');

        $resp = $this->controller()->index(new Request(), 'article');

        self::assertSame(200, $resp->getStatusCode());
        $rows = json_decode((string) $resp->getContent(), true)['data']['migrations'];
        self::assertSame([$uuid], array_column($rows, 'uuid'));
    }

    private function controller(): MigrationController
    {
        return $this->container()->get(MigrationController::class);
    }

    private function hydrate(array $ops): MigrationData
    {
        /** @var MigrationData $data */
        $data = (new RequestDataHydrator())->hydrate(MigrationData::class, ['ops' => $ops]);

        return $data;
    }

    private function createType(string $slug): string
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

    private function startMigration(string $slug): string
    {
        $resp = $this->controller()->store(
            $this->hydrate([['op' => 'rename', 'from' => 'title', 'to' => 'heading']]),
            new Request(),
            $slug
        );

        return (string) json_decode((string) $resp->getContent(), true)['data']['migration']['uuid'];
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    private function repo(): MigrationRepository
    {
        return new MigrationRepository($this->connection());
    }
}
