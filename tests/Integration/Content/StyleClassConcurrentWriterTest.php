<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Database\Connection;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * One concurrent style class writer, run as its own PHP process by
 * `StyleClassRepositoryTest::testConcurrentWritesToDifferentClassesSerialiseTheGeneration`.
 * Skipped unless `THALLO_STYLE_CLASS_WRITER` names it. It skips the harness's per-test
 * truncation on purpose: the launching test owns the tables while the writers run.
 */
final class StyleClassConcurrentWriterTest extends AppTestCase
{
    protected function setUp(): void
    {
        // No truncation, no seed grants: this process only writes one class.
    }

    public function testWriteOneClassWhileHoldingTheTransaction(): void
    {
        $name = (string) getenv('THALLO_STYLE_CLASS_WRITER');
        $dir = (string) getenv('THALLO_STYLE_CLASS_BARRIER');
        if ($name === '' || $dir === '') {
            self::markTestSkipped('launched only by StyleClassRepositoryTest');
        }
        touch("{$dir}/ready-{$name}");
        $deadline = microtime(true) + 20;
        while (!is_file("{$dir}/start")) {
            usleep(10000);
            self::assertLessThan($deadline, microtime(true), 'the barrier never opened');
        }
        $repository = $this->container()->get(StyleClassRepository::class);
        $repository->write(static function (Connection $db) use ($name): void {
            $db->table('style_classes')->insert([
                'id' => str_pad(strtolower($name), 12, '0'),
                'name' => $name,
                'name_key' => strtolower($name),
                'style' => '{}',
                'version' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            usleep(700000); // hold the transaction so both increments overlap on the row
        });
        self::assertNotNull($repository->find(str_pad(strtolower($name), 12, '0')));
    }
}
