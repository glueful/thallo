<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\MintsRegionTokens;

/**
 * A regions-stage token in the preview cookie is no session for ordinary pages (regions-stage spec
 * §4.7): with the canvas cookie too, a page renders exactly as a visitor sees it — no annotation,
 * no draft, no banner.
 */
final class RegionTokenCookieTest extends AppTestCase
{
    use MintsRegionTokens;
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    public function testARegionTokenInTheCookieDoesNotEditOrdinaryPages(): void
    {
        $this->seedBilingualPublishedEntry();
        $cookies = ['thallo_preview' => $this->regionToken(), 'thallo_preview_canvas' => '1'];

        foreach (['/', '/blog/hello'] as $path) {
            $withToken = (string) $this->handle(Request::create($path, 'GET', [], $cookies))->getContent();
            $this->container()->get(CacheStore::class)->deletePattern('render:*');
            $anonymous = (string) $this->handle(Request::create($path, 'GET'))->getContent();

            foreach (['data-thallo-block', 'data-thallo-slot', 'thallo-edit-region', 'preview-banner'] as $marker) {
                self::assertStringNotContainsString($marker, $withToken, "{$path}: {$marker}");
            }
            self::assertSame(
                $this->normalize($anonymous),
                $this->normalize($withToken),
                "{$path} differs from a visitor's",
            );
        }
    }

    /** Strip what legitimately varies per response (nonces, the render timestamp). */
    private function normalize(string $html): string
    {
        return (string) preg_replace(['~nonce="[^"]*"~', '~<!--.*?-->~s'], ['nonce=""', ''], $html);
    }
}
