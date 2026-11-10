<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Where the server encodes WebP, every responsive image offers it: a <picture class="thallo-picture">
 * (display: contents, so layout and selectors see the <img> as before) holding a
 * <source type="image/webp"> with the <img>'s own sizes, and the <img> in the upload's format as
 * the fallback. The site logo gets sized copies too, instead of the full upload on every page.
 */
final class ResponsiveImageMarkupTest extends AppTestCase
{
    /** @param list<array<string,mixed>> $list */
    private function render(array $list, bool $webp = true, ?string $darkLogo = null, ?string $region = null): string
    {
        $media = new class implements MediaUrlResolver {
            public function url(string $uuid): ?string
            {
                return '/api/blobs/' . $uuid;
            }
        };
        $variants = new class ($webp) implements MediaVariantUrlResolver {
            public function __construct(private readonly bool $webp)
            {
            }

            public function variants(string $uuid, array $widths): ?array
            {
                $set = static fn (string $q): string => implode(', ', array_map(
                    static fn (int $w): string => "/api/blobs/{$uuid}?width={$w}{$q} {$w}w",
                    $widths,
                ));
                return [
                    'src' => '/api/blobs/' . $uuid,
                    'srcset' => $set(''),
                    'webp' => $this->webp ? $set('&format=webp') : null,
                ];
            }
        };
        $logo = new class ($darkLogo) implements SiteLogoProvider {
            public function __construct(private readonly ?string $dark)
            {
            }

            public function siteLogoUuid(string $variant = 'light'): ?string
            {
                return $variant === 'dark' ? $this->dark : 'logolight001';
            }
        };
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: $media,
            siteLogo: $logo,
            mediaVariants: $variants,
        );
        $base = $this->appContext()->getBasePath();
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $ext->resetPerRenderState();

        return $ext->blocks($env, ['entry' => null, 'site' => ['name' => 'T'], 'region_slug' => $region], $list);
    }

    /** The <picture> around the <img> whose src is /api/blobs/$uuid, as [source sizes, img sizes]. */
    private static function picture(string $html, string $uuid): array
    {
        $found = preg_match(
            '~<picture class="thallo-picture"><source type="image/webp" srcset="/api/blobs/' . $uuid
                . '\?width=\d+&amp;format=webp \d+w[^"]*" sizes="([^"]*)"><img [^>]*src="/api/blobs/' . $uuid
                . '"[^>]*sizes="([^"]*)"[^>]*></picture>~',
            $html,
            $m,
        );
        self::assertSame(1, $found, "a WebP <picture> around {$uuid}'s <img>:\n{$html}");
        return [$m[1], $m[2]];
    }

    public function testHeroImageAndContainerBackgroundOfferWebpWithTheImgsOwnSizes(): void
    {
        $html = $this->render([
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'A', 'image' => 'heroimg00001']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'blockimg0001']],
            ['id' => 'c1', 'type' => 'container', 'data' => ['background_image' => 'contbg000001', 'content' => []]],
        ]);

        foreach (['heroimg00001', 'blockimg0001', 'contbg000001'] as $uuid) {
            [$source, $img] = self::picture($html, $uuid);
            self::assertSame($img, $source, "{$uuid}: the WebP source is chosen at the <img>'s sizes");
        }
        self::assertSame(1, substr_count($html, 'fetchpriority="high"'), 'the <img> keeps the priority claim');
    }

    public function testNoWebpMeansThePlainImgAsBefore(): void
    {
        $html = $this->render([
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'A', 'image' => 'heroimg00002']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['image' => 'blockimg0002']],
        ], webp: false);

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('format=webp', $html);
        self::assertSame(2, substr_count($html, 'srcset="'), 'the srcset in the upload format stays');
    }

    public function testLogoBlockIsSizedCopiesNotTheFullUpload(): void
    {
        $html = $this->render([['id' => 'l1', 'type' => 'logo', 'data' => []]], region: 'header');

        [$source, $img] = self::picture($html, 'logolight001');
        self::assertSame($img, $source);
        self::assertStringContainsString('/api/blobs/logolight001?width=320 320w', $html);
        self::assertStringContainsString('loading="eager"', $html, 'header chrome still loads eagerly');
        self::assertStringNotContainsString('fetchpriority', $html, 'brand imagery never claims');
    }

    public function testLightAndDarkLogosEachGetTheirOwnPicture(): void
    {
        $html = $this->render([['id' => 'l1', 'type' => 'logo', 'data' => []]], darkLogo: 'logodark0001');

        self::picture($html, 'logolight001');
        self::picture($html, 'logodark0001');
        self::assertStringContainsString('thallo-block-logo__image--dark', $html);
    }

    public function testEveryThemeTemplateThatBuildsASrcsetOffersWebp(): void
    {
        // Entry covers, blog cards, listing rows and post covers need entries to render; their
        // source must carry the same WebP source as the blocks rendered above.
        $dir = dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'srcset="{{')) {
                continue;
            }
            $checked++;
            self::assertStringContainsString(
                '<source type="image/webp"',
                $source,
                substr($file->getPathname(), strlen($dir) + 1) . ' builds a srcset without a WebP source',
            );
        }
        self::assertGreaterThanOrEqual(8, $checked);
    }
}
