<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeColorsStyleTest extends AppTestCase
{
    private function ext(string $savedAccent, string $savedNeutral): RenderContextExtension
    {
        $provider = new class ($savedAccent, $savedNeutral) implements ThemeAppearanceProvider {
            public function __construct(private string $a, private string $n)
            {
            }
            public function accent(): string
            {
                return $this->a;
            }
            public function neutral(): string
            {
                return $this->n;
            }
        };
        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
        );
    }

    public function testDefaultPairEmitsEmpty(): void
    {
        $out = (string) $this->ext('blue', 'slate')->themeColorsStyle();
        self::assertSame('', $out);
    }

    public function testNonDefaultSavedPairEmitsOverride(): void
    {
        $out = (string) $this->ext('emerald', 'zinc')->themeColorsStyle();
        self::assertStringContainsString(':root{', $out);
        self::assertStringContainsString('html[data-theme="dark"]{', $out);
        self::assertStringContainsString('--accent:#047857', $out);
    }

    public function testPreviewOverrideBeatsSaved(): void
    {
        $ext = $this->ext('rose', 'zinc');                 // saved non-default
        $ext->setThemeAppearanceOverride('blue', 'slate'); // preview = default
        self::assertSame('', (string) $ext->themeColorsStyle(), 'preview default over saved non-default emits nothing');
    }

    public function testInvalidOverrideFallsBackNotThrows(): void
    {
        $ext = $this->ext('blue', 'slate');
        $ext->setThemeAppearanceOverride('banana', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle()); // banana -> blue -> default -> empty
    }
}
