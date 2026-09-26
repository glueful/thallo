<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The map block: a Google map of a place, with no API key — Google's own embed, built here from
 * the address, zoom and view, or taken from Google's "Embed a map" link once it proves to be
 * one. Nothing typed ever reaches the page as an address of its own. Optionally the map waits
 * for a click before loading (and before Google sets its cookies), with a link to open it in
 * Google Maps meanwhile.
 */
final class MapBlockRenderTest extends AppTestCase
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

    /** @param array<string,mixed> $data */
    private function render(array $data): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'map0000001', 'type' => 'map', 'data' => $data]],
        ]);
    }

    public function testAPlaceBecomesAGoogleMapWithDirections(): void
    {
        $out = $this->render(['place' => 'Accra Mall, Accra', 'zoom' => 16, 'directions' => true]);

        self::assertStringContainsString('class="thallo-block thallo-block-map thallo-block-map--medium"', $out);
        self::assertStringContainsString(
            'src="https://www.google.com/maps?q=Accra+Mall%2C+Accra&amp;z=16&amp;t=m&amp;output=embed"',
            $out,
        );
        self::assertStringContainsString('title="Map of Accra Mall, Accra"', $out);
        self::assertStringContainsString('loading="lazy"', $out);
        self::assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $out);
        self::assertStringContainsString(
            'href="https://www.google.com/maps/dir/?api=1&amp;destination=Accra+Mall%2C+Accra"',
            $out,
        );
        self::assertStringContainsString('Get directions', $out);
    }

    public function testSatelliteViewAZoomHeldToGooglesRangeAndNoDirectionsWhenOff(): void
    {
        $out = $this->render(['place' => 'Kotoka Airport', 'zoom' => 99, 'view' => 'satellite', 'height' => 'large']);
        self::assertStringContainsString('q=Kotoka+Airport&amp;z=21&amp;t=k&amp;output=embed', $out);
        self::assertStringContainsString('thallo-block-map--large', $out);
        self::assertStringNotContainsString('Get directions', $out);

        self::assertStringContainsString('&amp;z=1&amp;', $this->render(['place' => 'Ghana', 'zoom' => -4]));
        self::assertStringContainsString('&amp;z=15&amp;', $this->render(['place' => 'Ghana']), 'zoom 15 by default');
    }

    public function testGooglesEmbedLinkPinsTheExactPlace(): void
    {
        $pb = '!1m18!1m12!1m3!1d3970.8!2d-0.17!3d5.62!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1'
            . '!3m3!1m2!1s0x0%3A0x0!2sAccra%20Mall!5e0!3m2!1sen!2sgh!4v1';
        $out = $this->render([
            'place' => 'Accra Mall',
            'embed_url' => "https://www.google.com/maps/embed?pb={$pb}",
            'directions' => true,
        ]);
        self::assertStringContainsString('src="https://www.google.com/maps/embed?pb=' . $pb . '"', $out);
        self::assertStringContainsString('destination=Accra+Mall', $out, 'directions still go to the place');

        // Pasted as the whole <iframe> Google hands out: its src is what counts.
        $iframe = '<iframe src="https://www.google.com/maps/embed?pb=' . $pb . '" width="600" height="450"></iframe>';
        self::assertStringContainsString(
            'src="https://www.google.com/maps/embed?pb=' . $pb . '"',
            $this->render(['embed_url' => $iframe]),
        );
    }

    public function testALinkThatIsNotGooglesEmbedIsNeverUsed(): void
    {
        $refused = [
            'https://evil.example/maps/embed?pb=!1m18',
            'https://www.google.com.evil.example/maps/embed?pb=!1m18',
            'http://www.google.com/maps/embed?pb=!1m18',
            'javascript:alert(1)',
            'https://www.google.com/maps/embed?pb=!1m18"><script>alert(1)</script>',
            'https://www.google.com/search?q=maps',
        ];
        foreach ($refused as $bad) {
            $out = $this->render(['place' => 'Accra', 'embed_url' => $bad]);
            self::assertStringContainsString('src="https://www.google.com/maps?q=Accra&amp;', $out, $bad);
            self::assertStringNotContainsString('evil', $out, $bad);
            self::assertStringNotContainsString('<script>alert', $out, $bad);
            // With no place to fall back on, there is no map at all.
            self::assertStringNotContainsString('<iframe', $this->render(['embed_url' => $bad]), $bad);
        }
    }

    public function testClickToLoadWaitsForTheVisitorAndOffersGoogleMapsMeanwhile(): void
    {
        $out = $this->render(['place' => 'Accra Mall, Accra', 'click_to_load' => true]);

        self::assertStringNotContainsString('<iframe', $out, 'nothing of Google loads until the click');
        self::assertStringContainsString(
            'data-map-src="https://www.google.com/maps?q=Accra+Mall%2C+Accra&amp;z=15',
            $out,
        );
        self::assertStringContainsString('class="thallo-block-map__load"', $out);
        self::assertStringContainsString('Show map', $out);
        self::assertStringContainsString(
            'href="https://www.google.com/maps/search/?api=1&amp;query=Accra+Mall%2C+Accra"',
            $out,
            'without the script, a link still gets there',
        );
        self::assertStringContainsString('/_thallo/runtime/block-map.js', $out);
        self::assertStringNotContainsString('block-map.js', $this->render(['place' => 'Accra']), 'only when needed');
    }

    public function testNothingToShowRendersNothingOnTheSite(): void
    {
        $out = $this->render(['place' => '   ']);
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('thallo-block-map__', $out);
    }
}
