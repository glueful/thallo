<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Core\Content\Style\SettingsValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\ColorsArtifacts;
use Thallo\Render\Style\RequestPalette;

/**
 * The workspace's colours stylesheet from palette to page (custom palette spec §3.4): linked by
 * theme_colors_style() after the compiled stylesheet only while a brand colour is configured,
 * published before it is linked and served by hash; adding or clearing a colour changes what an open
 * stage compares; any brand id is a valid stored value.
 */
final class ColorsStylesheetTest extends AppTestCase
{
    use PaletteFixtures;

    private function extension(): RenderContextExtension
    {
        $this->container()->get(RequestPalette::class)->refresh();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        return $extension;
    }

    private function head(): string
    {
        $this->container()->get(RequestPalette::class)->refresh();
        $html = (string) $this->handle(Request::create('/', 'GET'))->getContent();
        return substr($html, 0, strpos($html, '</head>') ?: 0);
    }

    public function testAPageLinksTheColoursStylesheetOnlyWhenAColourIsConfigured(): void
    {
        self::assertNull($this->extension()->colorsStylesheetUrl());
        self::assertStringNotContainsString('colors-', $this->head());

        $this->configure(1, 'Gold', '#8a6a2a');
        $url = (string) $this->extension()->colorsStylesheetUrl();
        self::assertMatchesRegularExpression('~\A/theme-assets/colors-[0-9a-f]{16}\.css\z~', $url);
        $head = $this->head();
        $link = '<link rel="stylesheet" href="' . $url . '">';
        self::assertStringContainsString($link, $head);
        self::assertLessThan(strpos($head, $link), strpos($head, 'settings-'), 'after the compiled stylesheet');
        self::assertStringContainsString($link . '<style>:root{', $head, 'right before the site colours');

        $res = $this->handle(Request::create($url, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        self::assertStringContainsString(
            ClassNames::selector(ClassNames::for('colors.text', 'color.brand-1')),
            (string) $res->getContent(),
        );
        $unknown = $this->handle(Request::create('/theme-assets/colors-0000000000000000.css', 'GET'));
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function testACurrentHashMissingFromThisNodeIsPublishedAndServed(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $url = (string) $this->extension()->colorsStylesheetUrl();
        $base = $this->container()->get(\Glueful\Bootstrap\ApplicationContext::class)->getBasePath();
        foreach (glob($base . '/storage/cache/style/colors/*/' . basename($url)) ?: [] as $file) {
            unlink($file);
        }
        $artifacts = $this->container()->get(ColorsArtifacts::class);
        (fn () => $this->memo = [])->call($artifacts);
        $res = $this->handle(Request::create($url, 'GET'));
        self::assertSame(200, $res->getStatusCode());
    }

    public function testTheValidatorAcceptsAnyBrandId(): void
    {
        $tok = ['type' => 'token', 'value' => 'color.brand-12'];
        [$clean, $errors] = (new SettingsValidator())->validate(
            ['style' => ['colors' => ['text' => $tok]]],
            StyleCapabilities::all(),
        );
        self::assertSame([], $errors);
        self::assertSame('color.brand-12', $clean['style']['colors']['text']['value']);
    }

    public function testTokenClassFollowsTheConfiguredIds(): void
    {
        self::assertSame('', $this->extension()->tokenClass('colors.text', 'color.brand-1'));
        $this->configure(1, 'Gold', '#8a6a2a');
        self::assertSame(
            ' ' . ClassNames::for('colors.text', 'color.brand-1'),
            $this->extension()->tokenClass('colors.text', 'color.brand-1'),
        );
        self::assertSame('', $this->extension()->tokenClass('colors.text', 'color.brand-12'));
    }

    public function testAddingOrClearingAColourChangesTheStageFingerprint(): void
    {
        $none = $this->appearanceFingerprint();
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->container()->get(RequestPalette::class)->refresh();
        $one = $this->appearanceFingerprint();
        self::assertNotSame($none, $one);
        $this->clear(1);
        $this->container()->get(RequestPalette::class)->refresh();
        self::assertNotSame($one, $this->appearanceFingerprint());
    }

    public function testBrandTwelvePaintsItsClassFromItsOwnStylesheet(): void
    {
        $this->configure(12, 'Teal', '#0f766e');
        $class = ClassNames::for('colors.text', 'color.brand-12');
        self::assertSame(' ' . $class, $this->extension()->tokenClass('colors.text', 'color.brand-12'));
        $url = (string) $this->extension()->colorsStylesheetUrl();
        $css = (string) $this->handle(Request::create($url, 'GET'))->getContent();
        self::assertStringContainsString(ClassNames::selector($class), $css);
        self::assertStringContainsString('--t-color-brand-12: var(--brand-12);', $css);
        self::assertStringContainsString('--brand-12:#0f766e', $this->head());
    }
}
