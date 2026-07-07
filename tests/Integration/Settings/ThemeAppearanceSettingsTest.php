<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\EngineThemeAppearanceProvider;
use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;

final class ThemeAppearanceSettingsTest extends AppTestCase
{
    public function testDefaultsAreBlueSlate(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('blue', $settings->themeAccent());
        self::assertSame('slate', $settings->themeNeutral());
    }

    public function testRoundTripThroughSave(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save(['theme_accent' => 'emerald', 'theme_neutral' => 'zinc']);
        self::assertSame('emerald', $settings->themeAccent());
        self::assertSame('zinc', $settings->themeNeutral());
    }

    public function testProviderReflectsSavedValues(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save(['theme_accent' => 'rose']);
        $provider = new EngineThemeAppearanceProvider($settings);
        self::assertSame('rose', $provider->accent());
        self::assertSame('slate', $provider->neutral());
    }
}
