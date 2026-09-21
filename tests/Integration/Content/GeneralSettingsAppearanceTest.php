<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Style\StyleArtifactCompiler;
use Thallo\Contracts\Style\StyleCompileFailed;
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

    public function testTheAccentMayBeTheSitesOwnBrandColour(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(theme_accent: '#0A7C66'));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        // Stored as the one spelling the stylesheet writes.
        self::assertSame('#0a7c66', $this->container()->get(GeneralSettings::class)->themeAccent());

        foreach (['#12345', 'rgb(1,2,3)', '#fff;}body{display:none'] as $bad) {
            self::assertSame(
                422,
                $controller->update(new UpdateGeneralSettingsData(theme_accent: $bad))->getStatusCode(),
                $bad,
            );
        }
        // The neutral stays a family: a whole grey scale cannot be derived from one colour.
        self::assertSame(
            422,
            $controller->update(new UpdateGeneralSettingsData(theme_neutral: '#777777'))->getStatusCode(),
        );
    }

    public function testASiteMaySetItsOwnTypefaces(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(
            theme_font: 'custom',
            theme_font_body: 'fontbody0001',
            theme_font_display: 'fonthead0001',
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $provider = new \Thallo\Core\Settings\EngineThemeAppearanceProvider(
            $this->container()->get(GeneralSettings::class),
        );
        self::assertSame('custom', $provider->font());
        self::assertSame(['body' => 'fontbody0001', 'display' => 'fonthead0001'], $provider->fontFaces());

        // '' takes a face off; the other stays.
        $controller->update(new UpdateGeneralSettingsData(theme_font_display: ''));
        self::assertSame(['body' => 'fontbody0001'], $provider->fontFaces());

        self::assertSame(
            422,
            $controller->update(new UpdateGeneralSettingsData(theme_font_body: '../../etc/passwd'))->getStatusCode(),
        );
        // The pairings a site can choose between grew, and an unknown one is still refused.
        $humanist = $controller->update(new UpdateGeneralSettingsData(theme_font: 'humanist'));
        self::assertSame(200, $humanist->getStatusCode());
        self::assertSame(422, $controller->update(new UpdateGeneralSettingsData(theme_font: 'comic'))->getStatusCode());
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

    /** Visual builder spec §2.4: a theme switch compiles the artifact first and never activates on failure. */
    public function testAThemeSwitchCompilesBeforeActivatingAndIs422WhenTheCompileFails(): void
    {
        $settings = $this->container()->get(GeneralSettings::class);
        $before = $settings->theme();
        $compiled = [];
        $compiler = new class ($compiled) implements StyleArtifactCompiler {
            public bool $fail = false;

            public function __construct(private array &$compiled)
            {
            }

            public function compile(?string $theme = null): string
            {
                if ($this->fail) {
                    throw new StyleCompileFailed('disk full');
                }
                $this->compiled[] = $theme;
                return str_repeat('a', 16);
            }
        };
        $controller = new GeneralSettingsController(
            $settings,
            $this->container()->get(ApplicationContext::class),
            themeValidator: $this->container()->get(PreviewThemeValidator::class),
            styleCompiler: $compiler,
        );

        $compiler->fail = true;
        $res = $controller->update(new UpdateGeneralSettingsData(theme: 'default'));
        self::assertSame(422, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertStringContainsString('disk full', json_encode($body['errors'] ?? $body));
        self::assertSame($before, $settings->theme(), 'nothing activated');

        $compiler->fail = false;
        $res = $controller->update(new UpdateGeneralSettingsData(theme: 'default'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['default'], $compiled, 'compiled for the theme being activated');
        self::assertSame('default', $settings->theme());

        $compiled = [];
        $controller->update(new UpdateGeneralSettingsData(theme_accent: 'violet'));
        self::assertSame([], $compiled, 'an unchanged theme is not recompiled');
    }
}
