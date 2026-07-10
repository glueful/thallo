<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Cache;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Tenancy\Cache\TenantHostCachePurger;

final class TenantHostCachePurgerTest extends AppTestCase
{
    public function testOnlyTargetTenantNamespacesArePurged(): void
    {
        $cache = $this->container()->get(CacheStore::class);
        $own = [
            'tenant:tenant000001:render:default:/' => 'page',
            'tenant:tenant000001:thallo:seo:sitemap:root' => 'map',
        ];
        $foreign = 'tenant:tenant000002:render:default:/';
        foreach ($own as $key => $value) {
            $cache->set($key, $value, 60);
        }
        $cache->set($foreign, 'foreign', 60);

        (new TenantHostCachePurger($cache))->purgeForTenant('tenant000001');

        foreach (array_keys($own) as $key) {
            self::assertNull($cache->get($key));
        }
        self::assertSame('foreign', $cache->get($foreign));
    }
}
