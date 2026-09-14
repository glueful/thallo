<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;

final class GeneralSettingsAppearanceTest extends AppTestCase
{
    public function testSaveRejectsUnknownAccent(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_accent: 'banana'));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testSaveRejectsUnknownNeutral(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_neutral: 'octarine'));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testSaveAcceptsValidPairAndPersists(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_accent: 'violet', theme_neutral: 'zinc'));
        self::assertSame(200, $res->getStatusCode());
        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('violet', $settings->themeAccent());
        self::assertSame('zinc', $settings->themeNeutral());
    }

    public function testSaveRejectsUnknownDesignValues(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $radius = $controller->update(new UpdateGeneralSettingsData(theme_radius: 'huge'));
        $font = $controller->update(new UpdateGeneralSettingsData(theme_font: 'comic'));
        $background = $controller->update(new UpdateGeneralSettingsData(theme_background: 'plaid'));
        self::assertSame(422, $radius->getStatusCode());
        self::assertSame(422, $font->getStatusCode());
        self::assertSame(422, $background->getStatusCode());
    }

    public function testSaveAcceptsDesignValuesAndTheProviderReflectsThem(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(
            theme_radius: 'sharp',
            theme_font: 'editorial',
            theme_background: 'tinted',
        ));
        self::assertSame(200, $res->getStatusCode());

        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('sharp', $settings->themeRadius());
        self::assertSame('editorial', $settings->themeFont());
        self::assertSame('tinted', $settings->themeBackground());

        $provider = new \Thallo\Core\Settings\EngineThemeAppearanceProvider($settings);
        self::assertSame('sharp', $provider->radius());
        self::assertSame('editorial', $provider->font());
        self::assertSame('tinted', $provider->background());
    }

    public function testDesignDefaultsAreTodaysLook(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        self::assertSame('round', $settings->themeRadius());
        self::assertSame('sans', $settings->themeFont());
        self::assertSame('plain', $settings->themeBackground());
    }
}
