<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\StyleArtifactCompiler;
use Thallo\Contracts\Style\StyleCompileFailed;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\PaletteSettings;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;

final class GeneralSettingsAppearanceTest extends AppTestCase
{
    use PaletteFixtures;

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

    public function testASiteMayChooseItsTypefacesFromTheLibrary(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(
            theme_font: 'custom',
            theme_font_text_family: 'serif',
            theme_font_headings_family: 'slab',
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $provider = new \Thallo\Core\Settings\EngineThemeAppearanceProvider(
            $this->container()->get(GeneralSettings::class),
        );
        self::assertSame('custom', $provider->font());
        self::assertSame(['text' => 'serif', 'headings' => 'slab'], $provider->fontFamilies());

        // '' takes a family off; the other stays.
        $controller->update(new UpdateGeneralSettingsData(theme_font_headings_family: ''));
        self::assertSame(['text' => 'serif'], $provider->fontFamilies());

        self::assertSame(
            422,
            $controller->update(new UpdateGeneralSettingsData(theme_font_text_family: '../../etc'))->getStatusCode(),
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

    public function testChangingWhatAPageShowsOfTheSiteClearsTheRenderedPages(): void
    {
        // The page cache is purged on ThemeAppearanceChanged, and that fired for colours and the
        // design only: a new logo, favicon, font file or site name was served stale for up to
        // render.cache_ttl. Each now clears it — and saving the same value again does not.
        $fired = 0;
        $this->container()->get(EventService::class)->addListener(
            ThemeAppearanceChanged::class,
            static function () use (&$fired): void {
                ++$fired;
            },
        );
        $controller = $this->container()->get(GeneralSettingsController::class);
        $store = $this->container()->get(SettingsStore::class);
        try {
            foreach (
                [
                    ['site_logo' => 'logo00000001'],
                    ['site_logo_dark' => 'logo00000002'],
                    ['site_favicon' => 'icon00000001'],
                    ['site_name' => 'Acme Studio'],
                ] as $change
            ) {
                $before = $fired;
                self::assertSame(200, $controller->update(new UpdateGeneralSettingsData(...$change))->getStatusCode());
                self::assertSame($before + 1, $fired, 'a change of ' . array_key_first($change));

                $controller->update(new UpdateGeneralSettingsData(...$change));
                self::assertSame($before + 1, $fired, 'the same ' . array_key_first($change) . ' again purges nothing');
            }
        } finally {
            foreach (['site_logo', 'site_logo_dark', 'site_favicon', 'site_name'] as $key) {
                $store->forget($key);
            }
        }
    }

    public function testAPaletteChangeFiresThemeAppearanceChangedAndAnUnchangedOneDoesNot(): void
    {
        $fired = 0;
        $this->container()->get(EventService::class)->addListener(
            ThemeAppearanceChanged::class,
            static function () use (&$fired): void {
                ++$fired;
            },
        );
        $controller = $this->container()->get(GeneralSettingsController::class);
        $store = $this->container()->get(SettingsStore::class);
        try {
            $controller->update(new UpdateGeneralSettingsData(
                theme_brand_colors: '{"base":0,"colors":[{"name":"Gold","hex":"#8a6a2a"}]}',
            ));
            self::assertSame(1, $fired);
            $controller->update(new UpdateGeneralSettingsData(
                theme_brand_colors: '{"base":1,"colors":[{"id":1,"name":"Gold","hex":"#8A6A2A"}]}',
            ));
            self::assertSame(1, $fired, 'the same normalised value: no event');
            $controller->update(new UpdateGeneralSettingsData(theme_dark_base: 'stone'));
            self::assertSame(2, $fired);
        } finally {
            foreach (['theme_brand_colors', 'theme_dark_base'] as $key) {
                $store->forget($key);
            }
        }
    }

    public function testBrandColoursAreSavedAsAListAndGetPermanentIds(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $res = $controller->update(new UpdateGeneralSettingsData(
            theme_brand_colors: '{"base":0,"colors":[{"name":"Gold dark","hex":"#8A6A2A"},'
                . '{"name":"Rose","hex":"#c98a8a"}]}',
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $palette = $this->container()->get(PaletteSettings::class)->palette();
        self::assertSame([1, 2], $palette->ids());
        self::assertSame('#8a6a2a', $palette->brand(1)?->hex);
        // The response carries the stored list: the ids it gave and the revision to edit from.
        $stored = json_decode((string) $res->getContent(), true)['data']['settings']['theme_brand_colors'];
        self::assertSame(1, BrandColors::parse($stored)[2]);

        $refusals = [
            ['{"base":1,"colors":[{"id":1,"name":"Gold dark","hex":"#8a6a2a"}]}', 'Remove a brand colour with Clear'],
            ['{"base":1,"colors":[{"id":1,"name":"Gold dark","hex":"#8a6a2a"},{"id":2,"name":"Rose","hex":"#c98a8a"},'
                . '{"name":"A","hex":"#111111"},{"name":"B","hex":"#222222"}]}', 'This site allows 3 brand colours'],
            ['{"base":1,"colors":[{"id":1,"name":"","hex":"#8a6a2a"}]}', 'a name (1–32 characters) and a hex colour'],
            ['', 'clear a brand colour with Clear'],
        ];
        foreach ($refusals as [$body, $message]) {
            $refused = $controller->update(new UpdateGeneralSettingsData(theme_brand_colors: $body));
            self::assertSame(422, $refused->getStatusCode(), $body);
            self::assertStringContainsString($message, (string) $refused->getContent(), $body);
        }
        self::assertSame([1, 2], $this->container()->get(PaletteSettings::class)->palette()->ids());
    }

    /** @return list<array{string, \Closure(): void}> what someone else may have done meanwhile */
    private function otherEdits(GeneralSettingsController $controller): array
    {
        $save = fn (string $colors) => $controller->update(new UpdateGeneralSettingsData(
            theme_brand_colors: '{"base":1,"colors":' . $colors . '}',
        ));
        return [
            ['a rename', fn () => $save('[{"id":1,"name":"Amber","hex":"#8a6a2a"},'
                . '{"id":2,"name":"Rose","hex":"#c98a8a"}]')],
            ['a re-colour', fn () => $save('[{"id":1,"name":"Gold","hex":"#000000"},'
                . '{"id":2,"name":"Rose","hex":"#c98a8a"}]')],
            ['a reorder', fn () => $save('[{"id":2,"name":"Rose","hex":"#c98a8a"},'
                . '{"id":1,"name":"Gold","hex":"#8a6a2a"}]')],
            ['an add', fn () => $save('[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":2,"name":"Rose","hex":"#c98a8a"},'
                . '{"name":"Teal","hex":"#0f766e"}]')],
            ['a Clear', fn () => $this->container()->get(PaletteMutations::class)->clear(2, null)],
        ];
    }

    public function testAStaleListIsRefusedWhateverChangedMeanwhile(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        foreach ($this->otherEdits($controller) as [$what, $edit]) {
            $this->container()->get(GeneralSettings::class)->save(['theme_brand_colors' => BrandColors::encode(
                [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')],
                [],
                1,
            )]);
            $edit(); // A, from revision 1
            $before = $this->container()->get(GeneralSettings::class)->storedValue('theme_brand_colors');
            // B, still holding revision 1, re-colours Gold
            $b = $controller->update(new UpdateGeneralSettingsData(
                theme_brand_colors: '{"base":1,"colors":[{"id":1,"name":"Gold","hex":"#123456"},'
                    . '{"id":2,"name":"Rose","hex":"#c98a8a"}]}',
            ));
            self::assertSame(409, $b->getStatusCode(), $what);
            $changed = 'Brand colours changed since you opened this page';
            self::assertStringContainsString($changed, (string) $b->getContent(), $what);
            $after = $this->container()->get(GeneralSettings::class)->storedValue('theme_brand_colors');
            self::assertSame($before, $after, $what);
        }
    }

    public function testTheResponseCarriesTheListThisSaveCommitted(): void
    {
        // Another request changes the list after this save commits and before its response is built
        // (ThemeAppearanceChanged fires in between): the response still describes what this save wrote.
        $controller = $this->container()->get(GeneralSettingsController::class);
        $settings = $this->container()->get(GeneralSettings::class);
        $changes = [
            'reorders' => static fn (array $c): array => array_reverse($c, true),
            'clears' => static fn (array $c): array => array_slice($c, 1, null, true),
        ];
        foreach ($changes as $what => $change) {
            $this->container()->get(SettingsStore::class)->forget('theme_brand_colors'); // a fresh list each round
            $settings->clearStoreCache();
            $once = true;
            $listener = function () use (&$once, $settings, $change): void {
                if (!$once) {
                    return;
                }
                $once = false;
                [$colors, $removed, $revision] = BrandColors::parse($settings->storedValue('theme_brand_colors') ?? '');
                $settings->save([
                    'theme_brand_colors' => BrandColors::encode($change($colors), $removed, $revision + 1),
                ]);
            };
            // EventService has no removeListener: the `$once` guard makes a spent listener inert.
            $this->container()->get(EventService::class)->addListener(ThemeAppearanceChanged::class, $listener);
            $res = $controller->update(new UpdateGeneralSettingsData(
                theme_brand_colors: '{"base":0,"colors":[{"name":"Gold","hex":"#8a6a2a"},'
                    . '{"name":"Rose","hex":"#c98a8a"}]}',
            ));
            self::assertSame(200, $res->getStatusCode(), $what);
            $returned = json_decode((string) $res->getContent(), true)['data']['settings']['theme_brand_colors'];
            [$colors, , $revision] = BrandColors::parse($returned);
            self::assertSame(1, $revision, $what);
            $names = array_map(static fn (BrandSlot $b): string => $b->name, $colors);
            self::assertSame([1 => 'Gold', 2 => 'Rose'], $names, $what);
            // the other request did land
            self::assertSame(2, BrandColors::parse($settings->storedValue('theme_brand_colors') ?? '')[2], $what);
        }
    }

    public function testClearReturnsTheListItWrote(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $written = $this->container()->get(PaletteMutations::class)->clear(1, null);
        [$colors, $removed] = BrandColors::parse((string) $written);
        self::assertSame([2], array_keys($colors));
        self::assertSame([1 => 'Gold'], $removed);
        self::assertNull($this->container()->get(PaletteMutations::class)->clear(9, null)); // not a colour
    }

    public function testAddingAndClearingThenAddingNeverReusesAnId(): void
    {
        $controller = $this->container()->get(GeneralSettingsController::class);
        $controller->update(new UpdateGeneralSettingsData(
            theme_brand_colors: '{"base":0,"colors":[{"name":"A","hex":"#111111"},'
                . '{"name":"B","hex":"#222222"},{"name":"C","hex":"#333333"}]}',
        ));
        $this->container()->get(PaletteMutations::class)->clear(3, null); // revision 1 → 2
        $controller->update(new UpdateGeneralSettingsData(
            theme_brand_colors: '{"base":2,"colors":[{"id":1,"name":"A","hex":"#111111"},'
                . '{"id":2,"name":"B","hex":"#222222"},{"name":"D","hex":"#444444"}]}',
        ));
        self::assertSame([1, 2, 4], $this->container()->get(PaletteSettings::class)->palette()->ids());
    }
}
