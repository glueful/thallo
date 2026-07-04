<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Helpers\Utils;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Navigation\MenuReader;
use Glueful\Lemma\Navigation\MenuRepository;
use Glueful\Lemma\Render\RenderContextExtension;
use Twig\Error\RuntimeError;

final class RenderContextTest extends LemmaTestCase
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
        // Render must not hard-depend on lemma-navigation.
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
    }
}
