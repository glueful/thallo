<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

final class ThemeColorsLayoutTest extends AppTestCase
{
    public function testStyleLandsAfterBlocksCssAndBeforeCustomCss(): void
    {
        $base = $this->appContext()->getBasePath();
        $provider = new class implements ThemeAppearanceProvider {
            public function accent(): string
            {
                return 'emerald';
            }
            public function neutral(): string
            {
                return 'zinc';
            }
        };
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
        );
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $html = $env->load('layout.twig')->render([
            'site' => ['locale' => 'en', 'name' => 'Test Site'],
            'preview' => false,
        ]);

        $blocksCss = strpos($html, 'blocks.css');
        $style = strpos($html, '--accent:#047857');
        self::assertNotFalse($style, 'override style present');
        self::assertGreaterThan($blocksCss, $style, 'style after blocks.css');
        self::assertStringContainsString('<style>:root{', $html);
    }
}
