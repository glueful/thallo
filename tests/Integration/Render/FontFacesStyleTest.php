<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Twig\Error\RuntimeError;

/**
 * Default-theme-font spec §3/§8: font_faces_style() — existence-aware emission, BYTE
 * identity between the (attribute-decoded) preload URL and the raw CSS url(), and the
 * complete sink-escaping contract under hostile input.
 */
final class FontFacesStyleTest extends AppTestCase
{
    private function ext(): RenderContextExtension
    {
        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->resetPerRenderState();
        return $ext;
    }

    public function testDefaultThemeEmitsPreloadAndBothFacesWithByteIdenticalUrls(): void
    {
        $html = (string) $this->ext()->fontFacesStyle(
            'Figtree',
            'fonts/figtree-roman-latin.woff2',
            'fonts/figtree-italic-latin.woff2',
        );

        self::assertMatchesRegularExpression(
            '/<link rel="preload" as="font" type="font\/woff2" href="[^"]+" crossorigin>/',
            $html,
        );
        self::assertSame(2, substr_count($html, '@font-face'), 'roman + italic faces');
        self::assertSame(1, substr_count($html, 'rel="preload"'), 'roman only is preloaded');
        self::assertSame(2, substr_count($html, 'font-display: swap'));
        self::assertStringContainsString('font-weight: 300 900', $html);

        // Identity: the HTML-attribute href DECODES to the same bytes the raw CSS url()
        // carries — and the style block itself contains no HTML entities at all.
        preg_match('/href="([^"]+)"/', $html, $m);
        $decodedHref = html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE);
        preg_match('/<style>(.*)<\/style>/s', $html, $s);
        self::assertStringContainsString('url("' . $decodedHref . '")', $s[1]);
        self::assertStringNotContainsString('&amp;', $s[1]);
    }

    public function testMissingRomanEmitsNothingAndMissingItalicOmitsOnlyTheItalic(): void
    {
        $ext = $this->ext();
        self::assertSame('', (string) $ext->fontFacesStyle('Figtree', 'fonts/nope.woff2'));

        $romanOnly = (string) $ext->fontFacesStyle(
            'Figtree',
            'fonts/figtree-roman-latin.woff2',
            'fonts/nope-italic.woff2',
        );
        self::assertSame(1, substr_count($romanOnly, '@font-face'));
        self::assertStringNotContainsString('font-style: italic', $romanOnly);
    }

    public function testPreviewContextChecksTheAlternateDirNotTheBootTheme(): void
    {
        $ext = $this->ext();
        // Alternate dir WITHOUT the fonts: boot theme has them, preview must not emit.
        $ext->setAssetContext('/_preview-assets/tok1', sys_get_temp_dir());
        self::assertSame('', (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2'));

        // Alternate dir WITH a font file: emits preview-base URLs.
        $dir = sys_get_temp_dir() . '/font-ctx-' . uniqid('', true);
        mkdir($dir . '/fonts', 0755, true);
        copy(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2',
            $dir . '/fonts/figtree-roman-latin.woff2',
        );
        $ext->resetPerRenderState();
        $ext->setAssetContext('/_preview-assets/tok2', $dir);
        $html = (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2');
        self::assertStringContainsString('href="/_preview-assets/tok2/fonts/figtree-roman-latin.woff2"', $html);
        unlink($dir . '/fonts/figtree-roman-latin.woff2');
        rmdir($dir . '/fonts');
        rmdir($dir);
        $ext->resetPerRenderState();
    }

    /**
     * @dataProvider hostileCssStringProvider
     */
    public function testHostileFamilyInputsCannotEscapeTheStyleElement(string $family): void
    {
        $html = (string) $this->ext()->fontFacesStyle(
            $family,
            'fonts/figtree-roman-latin.woff2',
        );
        self::assertStringNotContainsString('</style><script>', $html);
        self::assertSame(1, substr_count($html, '</style>'), 'only the helper\'s own closer');
        self::assertStringNotContainsString("\x01", $html);
        self::assertStringNotContainsString("\x7F", $html);
    }

    /** @return iterable<string,array{string}> */
    public static function hostileCssStringProvider(): iterable
    {
        yield 'quote and style close' => ['</style><script>x</script>"; font-family: "Evil'];
        yield 'backslash' => ['Figtree\\"); color: red; /*'];
        yield 'C0 control' => ["Figtree\x01Injected"];
        yield 'DEL control' => ["Figtree\x7FInjected"];
    }

    public function testHostileAssetBaseIsEscapedForHtmlAndCssIndependently(): void
    {
        $dir = sys_get_temp_dir() . '/font-hostile-' . uniqid('', true);
        mkdir($dir . '/fonts', 0755, true);
        copy(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/fonts/figtree-roman-latin.woff2',
            $dir . '/fonts/figtree-roman-latin.woff2',
        );

        $ext = $this->ext();
        $ext->setAssetContext('/preview?x="&y=</style>\\' . "\x01", $dir);
        $html = (string) $ext->fontFacesStyle('Figtree', 'fonts/figtree-roman-latin.woff2');

        self::assertStringContainsString('&quot;', $html, 'href is HTML-attribute escaped');
        preg_match('/<style>(.*)<\/style>/s', $html, $style);
        self::assertStringNotContainsString('&quot;', $style[1], 'CSS never receives HTML entities');
        self::assertStringNotContainsString('</style><script>', $html);
        self::assertStringNotContainsString("\x01", $style[1]);
        self::assertStringNotContainsString("\x7F", $style[1]);
        self::assertSame(1, substr_count($html, '</style>'));

        unlink($dir . '/fonts/figtree-roman-latin.woff2');
        rmdir($dir . '/fonts');
        rmdir($dir);
        $ext->resetPerRenderState();
    }

    public function testUnsafeRelativePathKeepsAssetExceptionBehavior(): void
    {
        // Unsafe rel paths keep asset()'s exception behavior.
        $this->expectException(RuntimeError::class);
        $this->ext()->fontFacesStyle('Figtree', '../../../etc/passwd');
    }
}
