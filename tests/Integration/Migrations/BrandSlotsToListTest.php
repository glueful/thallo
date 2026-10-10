<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Migrations;

use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;

/**
 * The revision-4 build's three brand keys exist only on development installs: migration 048
 * converts them into the list, keeping ids 1–3, and deletes them (custom palette spec §7). Its Clear
 * deleted a slot's row, so on a workspace that used the palette every id 1–3 not configured is
 * reserved — content may still name it, and it must never become a different colour.
 */
final class BrandSlotsToListTest extends AppTestCase
{
    use PaletteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // The audit log outlives each test: earlier palette tests' Clear entries would lend their names.
        $this->connection()->getPDO()->exec("DELETE FROM audit_logs WHERE target_type = 'palette_slot'");
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM audit_logs WHERE target_type = 'palette_slot'");
        parent::tearDown();
    }

    private function migrate(): void
    {
        require_once dirname(__DIR__, 3) . '/core/database/migrations/048_ConvertBrandSlotsToList.php';
        (new \ConvertBrandSlotsToList())->up($this->connection()->getSchemaBuilder());
        // The migration writes beside the settings store: the next read must see it.
        $this->container()->get(\Thallo\Core\Settings\GeneralSettings::class)->clearStoreCache();
    }

    private function put(string $key, string $value): void
    {
        $this->connection()->table('settings')->insert(['key' => $key, 'value' => $value]);
    }

    private function value(string $key): ?string
    {
        $row = $this->connection()->table('settings')->where(['key' => $key])->first();
        return $row === null ? null : (string) $row['value'];
    }

    public function testItKeepsIdsOneToThreeReservesTheRestAndDeletesTheOldKeys(): void
    {
        $this->put('theme_brand_1', '{"name":"Gold","hex":"#8a6a2a"}');
        $this->put('theme_brand_2', 'not json');
        $this->migrate();

        [$colors, $removed, $revision] = BrandColors::parse((string) $this->value('theme_brand_colors'));
        self::assertSame([1], array_keys($colors));
        self::assertSame([2 => 'Brand 2', 3 => 'Brand 3'], $removed);
        self::assertSame(0, $revision);
        foreach ([1, 2, 3] as $n) {
            self::assertNull($this->value("theme_brand_{$n}"));
        }
    }

    public function testAClearedRevisionFourSlotIsNeverReissued(): void
    {
        // Brand 3 was configured and cleared under revision 4: its row is gone, the palette row and
        // the audit entry remain, and a retained version still names it.
        $this->state()->snapshot(); // creates the palette_state row, as any palette use did
        $this->connection()->table('audit_logs')->insert([
            'uuid' => substr(bin2hex(random_bytes(6)), 0, 12), 'occurred_at' => '2026-10-09 10:00:00',
            'action' => 'palette.brand.cleared',
            'category' => 'content', 'target_type' => 'palette_slot', 'target_uuid' => 'brand-3',
            'target_label' => 'Teal',
        ]);
        $this->migrate();
        $palette = $this->container()->get(\Thallo\Core\Settings\PaletteSettings::class)->palette();
        self::assertSame('Teal', $palette->labelOf(3));
        self::assertTrue($palette->isRemoved(3));
        self::assertSame(3, $palette->highestIssued());
        // Task 4 adds: a colour saved after the migration takes 4, and an old reference to 3 stays unavailable.
    }

    public function testAFreshInstallReservesNothing(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM palette_state');
        $this->migrate();
        self::assertNull($this->value('theme_brand_colors'));
    }

    public function testItNeverOverwritesAList(): void
    {
        $list = '{"revision":2,"colors":[{"id":9,"name":"Rose","hex":"#c98a8a"}],"removed":[]}';
        $this->put('theme_brand_colors', $list);
        $this->put('theme_brand_1', '{"name":"Gold","hex":"#8a6a2a"}');
        $this->migrate();
        self::assertSame($list, $this->value('theme_brand_colors'));
        self::assertNull($this->value('theme_brand_1'));
    }
}
