<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;

/**
 * A site's own typefaces, from the settings to the page: the faces are media library files, named
 * in the settings by their uuid, and the page declares them by the URL the media library serves
 * them at — never by anything a setting says directly.
 */
final class ThemeCustomFontsTest extends AppTestCase
{
    /** @param array{body?: string, display?: string} $faces */
    private function provider(string $font, array $faces): ThemeAppearanceProvider
    {
        return new class ($font, $faces) implements ThemeAppearanceProvider {
            /** @param array{body?: string, display?: string} $faces */
            public function __construct(private string $font, private array $faces)
            {
            }
            public function accent(): string
            {
                return 'blue';
            }
            public function neutral(): string
            {
                return 'slate';
            }
            public function radius(): string
            {
                return 'round';
            }
            public function font(): string
            {
                return $this->font;
            }
            public function background(): string
            {
                return 'plain';
            }
            public function fontFaces(): array
            {
                return $this->faces;
            }
        };
    }

    /** @param array{body?: string, display?: string} $faces */
    private function ext(string $font, array $faces): RenderContextExtension
    {
        $provider = $this->provider($font, $faces);
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return str_starts_with($uuid, 'font') ? "/v1/blobs/{$uuid}" : null;
            }
        };
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
        );
        $ext->bindTheme(new ThemeLocator('default', $base . '/themes'));
        $ext->setAssetContext(null, $base . '/packages/thallo-render/themes/default/assets');
        return $ext;
    }

    public function testTheFacesAreDeclaredByTheUrlTheMediaLibraryServesThemAt(): void
    {
        $faces = ['body' => 'fontbody0001', 'display' => 'fonthead0001'];
        $css = (string) $this->ext('custom', $faces)->themeColorsStyle();
        self::assertStringContainsString('src:url("/v1/blobs/fontbody0001") format("woff2")', $css);
        self::assertStringContainsString('src:url("/v1/blobs/fonthead0001") format("woff2")', $css);
        self::assertStringContainsString('--font-body:"Site Body"', $css);
    }

    public function testAFaceTheMediaLibraryNoLongerHasIsSimplyNotUsed(): void
    {
        $faces = ['body' => 'gone00000001', 'display' => 'fonthead0001'];
        $css = (string) $this->ext('custom', $faces)->themeColorsStyle();
        self::assertStringNotContainsString('Site Body', $css);
        self::assertStringContainsString('Site Display', $css);
        // And faces are only ever used by the custom choice.
        self::assertSame('', (string) $this->ext('sans', ['body' => 'fontbody0001'])->themeColorsStyle());
    }

    public function testTheThemesOwnFaceIsNotDownloadedWhenNothingIsSetInIt(): void
    {
        $args = ['Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2'];
        self::assertStringContainsString('rel="preload"', (string) $this->ext('sans', [])->fontFacesStyle(...$args));
        self::assertStringContainsString('rel="preload"', (string) $this->ext('slab', [])->fontFacesStyle(...$args));
        self::assertSame('', (string) $this->ext('serif', [])->fontFacesStyle(...$args));
        self::assertSame('', (string) $this->ext('custom', ['body' => 'fontbody0001'])->fontFacesStyle(...$args));
        self::assertStringContainsString(
            'rel="preload"',
            (string) $this->ext('custom', ['display' => 'fonthead0001'])->fontFacesStyle(...$args),
            'only the headings changed: the text is still the theme face',
        );
    }

    public function testAPreviewCanTryFacesBeforeTheyAreSavedAndACachedPageIsReKeyedByThem(): void
    {
        $ext = $this->ext('sans', []);
        $ext->setThemeAppearanceOverride(null, null, ['font' => 'custom', 'font_body' => 'fontbody0001']);
        self::assertStringContainsString('/v1/blobs/fontbody0001', (string) $ext->themeColorsStyle());

        $plain = new ThemeAppearanceSource($this->provider('custom', []), new NullLogger());
        $faced = new ThemeAppearanceSource($this->provider('custom', ['body' => 'fontbody0001']), new NullLogger());
        self::assertNotSame($plain->fingerprint(), $faced->fingerprint());
        // With no faces, the fingerprint is the one it always had.
        self::assertSame('blue-slate-round-custom-plain', $plain->fingerprint());
        // A uuid is a uuid: anything else is not a face.
        $junk = new ThemeAppearanceSource($this->provider('custom', ['body' => '../etc/passwd']), new NullLogger());
        self::assertSame([], $junk->fontFaces());
    }
}
