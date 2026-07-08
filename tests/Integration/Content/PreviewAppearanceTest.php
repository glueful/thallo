<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Settings\EngineThemeAppearanceProvider;
use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\PreviewSession;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;

final class PreviewAppearanceTest extends AppTestCase
{
    public function testSessionCarriesAppearanceFields(): void
    {
        // Additive nullable fields — the VO accepts them without breaking arity.
        $s = new PreviewSession('tok', 'entry-uuid', 'en', null, null, 'emerald', 'zinc', 9999999999);
        self::assertSame('emerald', $s->accent);
        self::assertSame('zinc', $s->neutral);
    }

    public function testSavedNonDefaultPlusPreviewDefaultEmitsNoOverride(): void
    {
        // The user's pin: preview resolves INDEPENDENTLY of saved settings. Persist a
        // REAL non-default pair, then a preview override of the DEFAULT must emit
        // nothing — the preview page shows the default look, not the saved skin.
        $settings = $this->container()->get(GeneralSettings::class);
        $settings->save(['theme_accent' => 'rose', 'theme_neutral' => 'zinc']);

        $source = new ThemeAppearanceSource(
            new EngineThemeAppearanceProvider($settings),
            new NullLogger(),
        );
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: $source,
        );

        // Sanity: saved rose/zinc DOES emit an override with no preview active.
        self::assertNotSame('', (string) $ext->themeColorsStyle());

        // Preview override = default -> no override emitted, independent of saved.
        $ext->setThemeAppearanceOverride('blue', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle());
    }
}
