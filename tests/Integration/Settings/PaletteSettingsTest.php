<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Settings;

use Glueful\Http\Response;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\PaletteSettings;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §2: keys, hex forms, JSON shapes, 422s, normalisation, lifecycle on the server. */
final class PaletteSettingsTest extends AppTestCase
{
    private const SIX = '{"bg":"#F8F4EC","surface":"#fff","surface_2":"#efe7d8","ink":"#1b1712",'
        . '"muted":"#6b6156","line":"#e2d8c6"}';

    /** @param array<string,string> $args */
    private function save(array $args): Response
    {
        return $this->container()->get(GeneralSettingsController::class)
            ->update(new UpdateGeneralSettingsData(...$args));
    }

    private function palette(): \Thallo\Contracts\Style\Palette
    {
        return $this->container()->get(PaletteProvider::class)->palette();
    }

    public function testCustomNeutralIsStoredNormalised(): void
    {
        $res = $this->save([
            'theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX, 'theme_dark_base' => 'stone',
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $p = $this->palette();
        self::assertSame('#f8f4ec', $p->customNeutral['bg'] ?? null);
        self::assertSame('#ffffff', $p->customNeutral['surface'] ?? null);
        self::assertSame('stone', $p->darkBase);
        self::assertSame('custom', $this->container()->get(GeneralSettings::class)->themeNeutral());
    }

    public function testChoosingCustomWithoutADarkBaseKeepsTheFamilyItCameFrom(): void
    {
        self::assertSame(200, $this->save(['theme_neutral' => 'zinc'])->getStatusCode());
        $res = $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('zinc', $this->palette()->darkBase, 'dark mode stays the family the site had');
        // a stored dark base is never overwritten by a later switch
        $this->save(['theme_neutral' => 'stone']);
        $this->save(['theme_neutral' => 'custom']);
        self::assertSame('zinc', $this->palette()->darkBase);
    }

    public function testARefusedDefaultLocaleLeavesThePaletteUnwritten(): void
    {
        $res = $this->save([
            'theme_brand_colors' => '{"base":0,"colors":[{"name":"Gold","hex":"#8a6a2a"}]}',
            'default_locale' => 'zz',
        ]);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getContent());
        $this->container()->get(GeneralSettings::class)->clearStoreCache();
        self::assertNull($this->palette()->brand(1), 'nothing of a refused save is written');
    }

    public function testCustomRequiresAllSixValues(): void
    {
        $res = $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => '{"bg":"#fff"}']);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('theme_neutral_custom', (string) $res->getContent());
        self::assertSame(422, $this->save(['theme_neutral' => 'custom'])->getStatusCode());
    }

    public function testMalformedValuesAre422NamingTheField(): void
    {
        $bad = '{"bg":"red","surface":"#fff","surface_2":"#fff","ink":"#000","muted":"#000","line":"#000"}';
        foreach (
            [
            ['theme_neutral_custom', $bad],
            ['theme_neutral_custom', 'not json'],
            ['theme_dark_base', 'purple'],
            ['theme_brand_colors', '{"base":0,"colors":[{"name":"Gold","hex":"#12345"}]}'],
            ['theme_brand_colors', '{"base":0,"colors":[{"name":"","hex":"#123456"}]}'],
            ['theme_brand_colors', '{"base":0,"colors":[{"name":"' . str_repeat('x', 33) . '","hex":"#123456"}]}'],
            ['theme_brand_colors', '{"base":0,"colors":[{"name":"Gold","hex":"#fff;}body{"}]}'],
            ['theme_brand_colors', '{"colors":[{"name":"Gold","hex":"#123456"}]}'],
            ] as [$key, $value]
        ) {
            $res = $this->save([$key => $value]);
            self::assertSame(422, $res->getStatusCode(), "{$key}={$value}");
            self::assertStringContainsString($key, (string) $res->getContent());
        }
    }

    public function testABrandSlotIsStoredWithItsNameTrimmedAndHexNormalised(): void
    {
        $res = $this->save(['theme_brand_colors' => '{"base":0,"colors":[{"name":"  Gold dark ","hex":"#8A6A2A"}]}']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $slot = $this->palette()->brand(1);
        self::assertSame('Gold dark', $slot?->name);
        self::assertSame('#8a6a2a', $slot?->hex);
    }

    public function testCustomValuesAreKeptWhenTheNeutralSwitchesToAFamilyAndBack(): void
    {
        $this->save([
            'theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX, 'theme_dark_base' => 'stone',
        ]);
        $this->save(['theme_neutral' => 'zinc']);
        $p = $this->palette();
        self::assertSame('#f8f4ec', $p->customNeutral['bg'] ?? null);
        self::assertSame('stone', $p->darkBase);
        self::assertSame(200, $this->save(['theme_neutral' => 'custom'])->getStatusCode());
    }

    public function testResetClearsTheCustomValues(): void
    {
        $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX]);
        $res = $this->save(['theme_neutral' => 'stone', 'theme_neutral_custom' => '']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertNull($this->palette()->customNeutral);
    }

    public function testResetWhileCustomIsSelectedIsRefused(): void
    {
        $this->save(['theme_neutral' => 'custom', 'theme_neutral_custom' => self::SIX]);
        self::assertSame(422, $this->save(['theme_neutral_custom' => ''])->getStatusCode());
    }

    public function testAStoredValueThatNoLongerParsesReadsAsUnset(): void
    {
        $this->connection()->table('settings')->insert([
            'key' => 'theme_brand_colors',
            'value' => '{"colors":[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":2,"hex":"nope"}]}',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNull($this->palette()->brand(2));
        self::assertSame('Gold', $this->palette()->brand(1)?->name, 'the entries that parse stay');
    }

    public function testThePaletteReadsTheListWithItsRemovedNamesAndTheLimit(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['theme_brand_colors' => BrandColors::encode(
            [4 => new BrandSlot('Gold', '#8a6a2a'), 1 => new BrandSlot('Rose', '#c98a8a')],
            [2 => 'Teal'],
            1,
        )]);
        $palette = $this->container()->get(PaletteSettings::class)->palette();
        self::assertSame([4, 1], $palette->ids());
        self::assertSame('Teal', $palette->labelOf(2));
        self::assertSame(3, $palette->limit);
    }

    public function testLimitZeroHidesColoursAndKeepsThem(): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_colors' => BrandColors::encode([4 => new BrandSlot('Gold', '#8a6a2a')], [], 1),
        ]);
        $off = new PaletteSettings($this->container()->get(GeneralSettings::class), 0);
        self::assertSame([], $off->palette()->configured());
        $on = new PaletteSettings($this->container()->get(GeneralSettings::class), 3);
        self::assertSame([4], $on->palette()->ids());
    }
}
