<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Contracts\Capability\AvailabilityFingerprint;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderErrorCache;

/**
 * Cached pages are keyed by which features are on (search block spec §3.6): a hash of every
 * registered capability's evaluated state, taken from the snapshot the request renders with. A
 * change in configuration alone moves it, and a render that began before a change writes under the
 * key it began with, which no later request reads.
 */
final class AvailabilityFingerprintKeyTest extends AppTestCase
{
    public function testTheFingerprintIsStableAndChangesWithAnEvaluatedState(): void
    {
        $default = $this->container()->get(AvailabilityFingerprint::class)->current();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{12}\z/', $default);
        self::assertSame($default, $this->container()->get(AvailabilityFingerprint::class)->current());

        // A configuration-only change: no switch is written.
        $searchOn = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        self::assertNotSame($default, self::fingerprintOf($searchOn));
    }

    public function testThePageAndErrorKeysCarryTheFingerprint(): void
    {
        $fp = $this->container()->get(AvailabilityFingerprint::class)->current();
        $page = $this->container()->get(RenderPageCache::class);
        $key = (new \ReflectionMethod($page, 'key'))->invoke($page, '/');
        self::assertStringContainsString('-a' . $fp . ':', $key);

        $errors = $this->container()->get(RenderErrorCache::class);
        $errorKey = (new \ReflectionMethod($errors, 'key'))->invoke($errors, 404);
        self::assertStringContainsString('-a' . $fp . ':', $errorKey);
    }

    public function testAFlipChangesTheShopCatalogKeyToo(): void
    {
        $shop = $this->container()->get(ShopPageCache::class);
        $key = (new \ReflectionMethod($shop, 'key'))->invoke($shop, 't', 'en', '/shop', 1);

        $searchOn = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        $other = $searchOn->getContainer()->get(ShopPageCache::class);
        $otherKey = (new \ReflectionMethod($other, 'key'))->invoke($other, 't', 'en', '/shop', 1);

        self::assertStringContainsString('-a' . self::fingerprintOf($searchOn) . ':', $otherKey);
        self::assertNotSame($key, $otherKey);
    }

    public function testAnOldRenderFinishingAfterTheChangeIsNotServed(): void
    {
        // The key is taken before the render runs; a render that straddles a change stores its page
        // under the old fingerprint, and the next request — under the new one — misses it.
        $fingerprint = 'aaaaaaaaaaaa';
        $cache = $this->container()->get(CacheStore::class);
        $mw = new RenderPageCache($cache, 'default', static function () use (&$fingerprint): string {
            return 'appearance-a' . $fingerprint;
        }, true, 60);

        $renders = 0;
        $first = $mw->handle(Request::create('/straddle'), static function () use (&$fingerprint, &$renders): Response {
            $renders++;
            $fingerprint = 'bbbbbbbbbbbb'; // features change while this render is under way
            return new Response('<p>old</p>', 200, ['Content-Type' => 'text/html']);
        });
        self::assertStringContainsString('old', (string) $first->getContent());

        $second = $mw->handle(Request::create('/straddle'), static function () use (&$renders): Response {
            $renders++;
            return new Response('<p>new</p>', 200, ['Content-Type' => 'text/html']);
        });
        self::assertSame(2, $renders, 'the straddling render must not be served under the new state');
        self::assertStringContainsString('new', (string) $second->getContent());
    }

    private static function fingerprintOf(ApplicationContext $context): string
    {
        return $context->getContainer()->get(AvailabilityFingerprint::class)->current();
    }
}
