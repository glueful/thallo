<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Database\Connection;
use Thallo\Core\Content\Style\Classes\StyleClassNameTaken;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassVersionConflict;
use Thallo\Core\Content\Style\SiteStyleGeneration;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §4.1, §4.3: style class records with optimistic versions, case-insensitive
 * unique names and stable ids; every class write increments the site's style generation in the
 * same transaction, structurally — both failure directions and concurrent writers are forced.
 */
final class StyleClassRepositoryTest extends AppTestCase
{
    private function repository(): StyleClassRepository
    {
        return $this->container()->get(StyleClassRepository::class);
    }

    private function generation(): SiteStyleGeneration
    {
        return $this->container()->get(SiteStyleGeneration::class);
    }

    /** @return array<string,mixed> */
    private static function band(): array
    {
        return ['name' => 'Hero band', 'description' => 'The hero strip', 'style' => [
            'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.xl']]]],
        ]];
    }

    public function testCreateReadUpdateAndArchiveWithAnOptimisticVersion(): void
    {
        $start = $this->generation()->current();
        $created = $this->repository()->create(self::band());
        self::assertSame(12, strlen($created['id']));
        self::assertSame(1, $created['version']);
        self::assertSame('Hero band', $created['name']);
        self::assertSame('spacing.xl', $created['style']['spacing']['padding']['top']['md']['value']);
        self::assertFalse($created['archived']);
        self::assertSame($start + 1, $this->generation()->current(), 'a create is a class write');

        $updated = $this->repository()->update($created['id'], 1, ['name' => 'Hero strip']);
        self::assertSame(2, $updated['version']);
        self::assertSame($created['id'], $updated['id'], 'rename keeps the id');
        self::assertSame($start + 2, $this->generation()->current());

        try {
            $this->repository()->update($created['id'], 1, ['description' => 'stale']);
            self::fail('a stale version must conflict');
        } catch (StyleClassVersionConflict $e) {
            self::assertSame(2, $e->currentVersion);
        }
        self::assertSame($start + 2, $this->generation()->current(), 'a refused write leaves the generation');

        $archived = $this->repository()->archive($created['id']);
        self::assertTrue($archived['archived']);
        self::assertNotNull($this->repository()->find($created['id']), 'archive keeps the row');
        self::assertSame($start + 3, $this->generation()->current());
        self::assertCount(1, $this->repository()->all());
        self::assertCount(0, $this->repository()->all(includeArchived: false));
    }

    public function testNamesAreSiteUniqueCaseInsensitively(): void
    {
        $this->repository()->create(self::band());
        $start = $this->generation()->current();
        try {
            $this->repository()->create(['name' => '  HERO BAND ', 'style' => []]);
            self::fail('the same name in another case must be refused');
        } catch (StyleClassNameTaken) {
            // expected
        }
        self::assertSame($start, $this->generation()->current(), 'a refused write leaves the generation');
        $other = $this->repository()->create(['name' => 'Quiet', 'style' => []]);
        try {
            $this->repository()->update($other['id'], 1, ['name' => 'hero band']);
            self::fail('a rename onto another class\'s name must be refused');
        } catch (StyleClassNameTaken) {
            // expected
        }
    }

    public function testAFailingIncrementRollsTheClassRowBack(): void
    {
        $db = $this->connection();
        $throwing = new class ($db, $this->appContext()) extends SiteStyleGeneration {
            public function incrementWithin(Connection $db): int
            {
                throw new \RuntimeException('generation write failed');
            }
        };
        $repository = new StyleClassRepository($db, $throwing);
        $start = $this->generation()->current();
        try {
            $repository->create(self::band());
            self::fail('the failure must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('generation write failed', $e->getMessage());
        }
        self::assertSame([], $this->repository()->all(), 'no class row without its generation increment');
        self::assertSame($start, $this->generation()->current());
    }

    public function testAFailingRowWriteLeavesTheGenerationUnchanged(): void
    {
        $this->repository()->create(self::band());
        $start = $this->generation()->current();
        // Bypass the pre-check so the database's own unique index refuses the row.
        try {
            $this->repository()->write(function (Connection $db): void {
                $db->table('style_classes')->insert([
                    'id' => 'dupe00000001', 'name' => 'Dupe', 'name_key' => 'hero band', 'style' => '{}',
                    'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            });
            self::fail('the unique index must refuse the row');
        } catch (\Throwable) {
            // expected
        }
        self::assertSame($start, $this->generation()->current());
        self::assertNull($this->repository()->find('dupe00000001'));
    }

    public function testConcurrentWritesToDifferentClassesSerialiseTheGeneration(): void
    {
        // Two writer processes (each with its own database session) released together by a
        // barrier, each holding its transaction open after its row write: the increments overlap
        // on the generation row and the database serialises them.
        $start = $this->generation()->current();
        $dir = sys_get_temp_dir() . '/thallo-style-class-writers-' . getmypid();
        @mkdir($dir);
        array_map('unlink', glob("{$dir}/*") ?: []);
        $root = dirname(__DIR__, 3);
        $processes = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $command = [
                PHP_BINARY, "{$root}/vendor/bin/phpunit", '--no-coverage', '--no-configuration',
                '--bootstrap', "{$root}/vendor/autoload.php",
                "{$root}/tests/Integration/Content/StyleClassConcurrentWriterTest.php",
            ];
            $env = getenv() + ['THALLO_STYLE_CLASS_WRITER' => $name, 'THALLO_STYLE_CLASS_BARRIER' => $dir];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            self::assertIsResource($process, 'writer process');
            $processes[$name] = [$process, $pipes];
        }
        $deadline = microtime(true) + 30;
        while (!is_file("{$dir}/ready-Alpha") || !is_file("{$dir}/ready-Beta")) {
            usleep(20000);
            self::assertLessThan($deadline, microtime(true), 'the writers never became ready');
        }
        touch("{$dir}/start");
        foreach ($processes as $name => [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $exit = proc_close($process);
            self::assertSame(0, $exit, "writer {$name} failed:\n{$out}");
        }
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);

        self::assertSame($start + 2, $this->generation()->current(), 'two writers never write the same value');
        self::assertCount(2, $this->repository()->all());
    }
}
