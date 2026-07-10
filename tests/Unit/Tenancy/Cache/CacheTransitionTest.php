<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Cache;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Tenancy\Cache\CacheTransition;

final class CacheTransitionTest extends AppTestCase
{
    public function testPurgeClearsLegacyAndSegmentedRenderAndSitemapKeys(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $keys = [
            'render:default:light:/' => ['x'],
            'tenant:abc123456789:render:default:light:/' => ['x'],
            'thallo:seo:sitemap:root' => 's',
            'tenant:abc123456789:thallo:seo:sitemap:root' => 's',
        ];
        foreach ($keys as $key => $value) {
            $cache->set($key, $value, 3600);
        }

        (new CacheTransition($cache))->purge();

        foreach (array_keys($keys) as $key) {
            self::assertNull($cache->get($key));
        }
    }

    public function testConfiguredDriverSupportsPatternPurge(): void
    {
        self::assertTrue((new CacheTransition($this->container()->get(CacheStore::class)))->supportsPatternPurge());
    }

    public function testNoOpPatternDriverIsRejected(): void
    {
        $values = [];
        $cache = $this->createMock(CacheStore::class);
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$values): bool {
            $values[$key] = $value;
            return true;
        });
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$values): mixed {
            return $values[$key] ?? null;
        });
        $cache->method('deletePattern')->willReturn(false);
        $cache->method('delete')->willReturnCallback(static function (string $key) use (&$values): bool {
            unset($values[$key]);
            return true;
        });

        self::assertFalse((new CacheTransition($cache))->supportsPatternPurge());
    }
}
