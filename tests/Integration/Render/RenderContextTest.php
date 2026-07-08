<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Navigation\MenuReader;
use Thallo\Contracts\Settings\SiteFaviconProvider;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Navigation\MenuRepository;
use Thallo\Render\ActiveThemeSource;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Twig\Error\RuntimeError;

final class RenderContextTest extends AppTestCase
{
    use SeedsPublishedContent;

    private function extension(): RenderContextExtension
    {
        return new RenderContextExtension(
            $this->container()->get(MenuReader::class),
            $this->container()->get(EntryTargetResolver::class),
            'en',
        );
    }

    private function extensionWithoutReader(): RenderContextExtension
    {
        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
        );
    }

    public function testMenuRendersRealNavigationData(): void
    {
        $menus = $this->container()->get(MenuRepository::class);
        $menu = $menus->createMenu('main', 'Main');
        $menus->replaceTree((string) $menu['uuid'], 0, [[
            'uuid' => Utils::generateNanoID(),
            'parent_uuid' => null,
            'position' => 0,
            'kind' => 'url',
            'entry_uuid' => null,
            'url' => '/about',
            'labels' => json_encode(['en' => 'About']),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]]);

        $tree = $this->extension()->menu('main');
        self::assertSame('About', $tree[0]['label']);
        self::assertSame('/about', $tree[0]['url']);
    }

    public function testMenuIsEmptyWithoutAReader(): void
    {
        // Render must not hard-depend on thallo-navigation.
        self::assertSame([], $this->extensionWithoutReader()->menu('main'));
        self::assertSame([], $this->extension()->menu('does-not-exist'));
    }

    public function testPathIsNullUnlessPublished(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $ext = $this->extension();
        self::assertStringContainsString('/blog/hello', (string) $ext->path($entry));
        self::assertNull($ext->path('nope00000000'));
    }

    public function testAssetBustsCacheOnThemeSwitchAndOnContentEdit(): void
    {
        // Live base with the active theme wired: asset() carries the theme buster
        // (?t=) AND a per-file content fingerprint (&v=<mtime>) so an in-place edit
        // to a theme asset re-fetches immediately instead of waiting out max-age.
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            themeSource: $this->container()->get(ActiveThemeSource::class),
            themeAssetsDir: $this->container()->get(ThemeLocator::class)->activePaths()['assets'],
        );
        self::assertMatchesRegularExpression(
            '#^/theme-assets/blocks\.css\?t=[^&]+&v=\d+$#',
            $ext->asset('blocks.css'),
        );
        // A theme-relative path with no file on disk still gets ?t= but no &v=.
        self::assertMatchesRegularExpression(
            '#^/theme-assets/nope\.css\?t=[^&]+$#',
            $ext->asset('nope.css'),
        );
    }

    public function testAssetSafety(): void
    {
        $ext = $this->extension();
        self::assertSame('/theme-assets/css/site.css', $ext->asset('css/site.css'));

        foreach (['../x', '/x', 'https://evil.test/x', 'a\\b', ''] as $bad) {
            try {
                $ext->asset($bad);
                self::fail("asset('{$bad}') must be rejected");
            } catch (RuntimeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLocaleDrivesMenuAndPath(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $ext = $this->extension();
        $ext->setLocale('fr');
        self::assertStringContainsString('/fr/blog/bonjour', (string) $ext->path($entry));
    }

    public function testVideoEmbedParsesKnownProvidersOnly(): void
    {
        $ext = $this->extensionWithoutReader();

        self::assertSame(
            ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            $ext->videoEmbed('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
        );
        self::assertSame(
            ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            $ext->videoEmbed('https://youtu.be/dQw4w9WgXcQ'),
        );
        self::assertSame(
            ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            $ext->videoEmbed('https://www.youtube.com/shorts/dQw4w9WgXcQ'),
        );
        self::assertSame(
            ['provider' => 'vimeo', 'id' => '123456789'],
            $ext->videoEmbed('https://vimeo.com/123456789'),
        );
        self::assertSame(
            ['provider' => 'vimeo', 'id' => '123456789'],
            $ext->videoEmbed('https://player.vimeo.com/video/123456789'),
        );

        // Everything else is null — templates render NOTHING (never a raw iframe).
        self::assertNull($ext->videoEmbed('https://evil.test/watch?v=dQw4w9WgXcQ'));
        self::assertNull($ext->videoEmbed('https://www.youtube.com/watch?v=<script>'));
        self::assertNull($ext->videoEmbed('javascript:alert(1)'));
        self::assertNull($ext->videoEmbed('not a url'));
        self::assertNull($ext->videoEmbed('https://vimeo.com/not-digits'));
    }

    public function testSiteLogoIsNullWhenUnbound(): void
    {
        // Soft-bound seam: minimal wiring (no SiteLogoProvider) must not fail.
        self::assertNull($this->extensionWithoutReader()->siteLogo());
        self::assertNull($this->extensionWithoutReader()->siteFavicon());
    }

    public function testSiteLogoVariantVocabularyIsClosed(): void
    {
        // Site-identity P2 pin: null|'light'|'dark' only — a DB template can
        // never turn the argument into an unbounded settings lookup.
        $ext = $this->identityExtension(light: 'light0000001', dark: 'dark00000001');

        self::assertSame('/media/light0000001', $ext->siteLogo());
        self::assertSame('/media/light0000001', $ext->siteLogo('light'));
        self::assertSame('/media/dark00000001', $ext->siteLogo('dark'));
        self::assertNull($ext->siteLogo('weird'));
        self::assertNull($ext->siteLogo('site_favicon'));
    }

    public function testDarkLogoUnsetFallsThroughToNull(): void
    {
        // Dark is an OVERRIDE: unset → null → templates render the light logo.
        $ext = $this->identityExtension(light: 'light0000001', dark: null);
        self::assertSame('/media/light0000001', $ext->siteLogo());
        self::assertNull($ext->siteLogo('dark'));
    }

    public function testSiteFaviconObeysTheMediaPredicate(): void
    {
        // Site-identity P1 pin: the uuid resolves through the SAME MediaUrlResolver
        // predicate — a non-anonymously-servable blob yields null (no link tag).
        $set = $this->identityExtension(favicon: 'favic0000001');
        self::assertSame('/media/favic0000001', $set->siteFavicon());

        $private = $this->identityExtension(favicon: 'denied000001');
        self::assertNull($private->siteFavicon());

        $unset = $this->identityExtension(favicon: null);
        self::assertNull($unset->siteFavicon());
    }

    /**
     * An extension with stub identity providers and a MediaUrlResolver that
     * resolves every uuid EXCEPT 'denied000001' (the not-public case).
     */
    private function identityExtension(
        ?string $light = null,
        ?string $dark = null,
        ?string $favicon = null,
    ): RenderContextExtension {
        $logos = new class ($light, $dark) implements SiteLogoProvider {
            public function __construct(private readonly ?string $light, private readonly ?string $dark)
            {
            }

            public function siteLogoUuid(string $variant = 'light'): ?string
            {
                return match ($variant) {
                    'light' => $this->light,
                    'dark' => $this->dark,
                    default => null,
                };
            }
        };
        $favicons = new class ($favicon) implements SiteFaviconProvider {
            public function __construct(private readonly ?string $favicon)
            {
            }

            public function faviconUuid(): ?string
            {
                return $this->favicon;
            }
        };
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return $uuid === 'denied000001' ? null : '/media/' . $uuid;
            }
        };

        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            siteLogo: $logos,
            favicon: $favicons,
        );
    }
}
