<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Navigation\MenuUpdated;

/**
 * MenuUpdated → invalidateTags(['thallo:shop:catalog']).
 *
 * A menu renders in the header and footer on shop pages too, but those pages are held in the SHOP
 * cache. `MenuUpdated` had a single subscriber — {@see \Thallo\Render\Listeners\PurgeRenderCacheOnMenuUpdate},
 * which purges `thallo:render:page` only — so a menu edit showed on every page EXCEPT `/shop`, its
 * categories and products, which kept the old menu until their own TTL lapsed.
 *
 * The event names a menu, not a tenant, so the GLOBAL catalog tag is the blast radius — the
 * reasoning of {@see PurgeShopCacheOnRegionUpdate}, whose sibling this is.
 */
final class PurgeShopCacheOnMenuUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onMenuUpdated(object $event): void
    {
        if (!$event instanceof MenuUpdated) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog']);
    }
}
