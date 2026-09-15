<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §4.1: the `style_classes` table — site-owned records with a stable id,
 * a case-insensitive unique name and an optimistic version.
 */
final class StyleClassesTableTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private static function row(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'name_key' => mb_strtolower(trim($name)),
            'description' => null,
            'style' => json_encode(['radius' => ['type' => 'token', 'value' => 'radius.lg']], JSON_THROW_ON_ERROR),
            'version' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function testTheTableHoldsARecordWithItsDefaults(): void
    {
        $db = $this->connection();
        $db->table('style_classes')->insert(self::row('band00000001', 'Hero band'));
        $row = $db->table('style_classes')->where('id', '=', 'band00000001')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['version']);
        self::assertSame(0, (int) $row['reference_guard']);
        self::assertNull($row['archived_at']);
        self::assertNull($row['locked_by_job']);
        self::assertSame('hero band', $row['name_key']);
    }

    public function testTheNameKeyIsUnique(): void
    {
        $db = $this->connection();
        $db->table('style_classes')->insert(self::row('band00000001', 'Hero band'));
        $this->expectException(\Throwable::class);
        $db->table('style_classes')->insert(self::row('band00000002', 'HERO BAND'));
    }
}
