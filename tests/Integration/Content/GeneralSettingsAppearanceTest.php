<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Http\Controllers\GeneralSettingsController;
use App\Http\DTOs\UpdateGeneralSettingsData;
use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;

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
}
