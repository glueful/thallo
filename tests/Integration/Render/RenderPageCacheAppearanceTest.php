<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderErrorCache;

final class RenderPageCacheAppearanceTest extends AppTestCase
{
    public function testPageKeyIncludesAppearanceFingerprint(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $mw = new RenderPageCache($cache, 'default', 'emerald-zinc', true, 60);
        $ref = new \ReflectionMethod($mw, 'key');
        $ref->setAccessible(true);
        self::assertSame('render:default:emerald-zinc:%2F', $ref->invoke($mw, '/'));
    }

    public function testErrorKeyIncludesAppearanceFingerprint(): void
    {
        // Cached 404/410 chrome carries the theme's token styles, so error keys
        // must vary by appearance too (spec §7).
        $cache = $this->container()->get(CacheStore::class);
        $errors = new RenderErrorCache($cache, 'default', 'emerald-zinc', true, 60);
        $ref = new \ReflectionMethod($errors, 'key');
        $ref->setAccessible(true);
        self::assertSame('render:default:emerald-zinc:404', $ref->invoke($errors, 404));
    }
}
