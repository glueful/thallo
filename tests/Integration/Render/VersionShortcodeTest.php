<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Delivery\SiteVersionProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\SiteContext;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Website plan decision 7: the landing page shows the running version through a shortcode
 * rendered from the install's own version, correct by construction on every deploy. The
 * version reaches templates as `site.version` (built by SiteContext from the SiteVersionProvider
 * contract core binds to Composer's registry); `shortcodes/thallo-version.twig` prints it.
 */
final class VersionShortcodeTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    private function shortcode(array $params, array $site): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [[
                'id' => 'v1',
                'type' => 'shortcode',
                'data' => ['name' => 'thallo-version', 'params' => $params],
            ]],
            'site' => $site,
        ]);
    }

    public function testItRendersTheSiteVersionWithAnOptionalPrefix(): void
    {
        $out = $this->shortcode(['prefix' => 'Developer Preview '], ['name' => 'Thallo', 'version' => '1.0.0-beta.26']);

        self::assertStringContainsString('data-shortcode="thallo-version"', $out);
        self::assertStringContainsString(
            '<span class="thallo-shortcode-version">Developer Preview 1.0.0-beta.26</span>',
            $out,
        );
    }

    public function testADevelopmentCheckoutSaysSoInsteadOfAVersion(): void
    {
        $out = $this->shortcode([], ['name' => 'Thallo', 'version' => null]);

        self::assertStringContainsString('thallo-shortcode-version--development', $out);
        self::assertStringContainsString('development checkout', $out);
    }

    public function testCoreBindsTheProviderAndThisCheckoutReportsDevelopment(): void
    {
        $provider = $this->container()->get(SiteVersionProvider::class);

        self::assertInstanceOf(SiteVersionProvider::class, $provider);
        self::assertNull($provider->installedVersion(), 'thallo-core is a path package here');
    }

    public function testSiteContextCarriesNameLocaleAndVersion(): void
    {
        $site = SiteContext::build($this->appContext(), 'en');

        self::assertSame(['name', 'locale', 'locales', 'version'], array_keys($site));
        self::assertSame('en', $site['locale']);
        self::assertNull($site['version']);
    }

    public function testTheDefaultThemeStylesTheVersionAsAPill(): void
    {
        $css = (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/themes/default/assets/blocks.css'
        );

        // A bare <span> is the floor; the default theme dresses it as a status pill with a dot,
        // driven by theme tokens so a site's custom CSS only has to recolour it.
        self::assertStringContainsString('.thallo-shortcode-version {', $css);
        self::assertStringContainsString('.thallo-shortcode-version::before', $css);
        self::assertStringContainsString('--version-fg', $css);
        self::assertStringContainsString('--version-bg', $css);
    }
}
