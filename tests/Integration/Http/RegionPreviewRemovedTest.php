<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Routing\Router;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\TwigFactory;

/**
 * The stage replaced the Header & footer page's separate preview (regions-stage spec §6): its route
 * and template are gone, and the stage's own endpoints are what remain.
 */
final class RegionPreviewRemovedTest extends AppTestCase
{
    public function testTheOldPreviewRouteIsGoneAndTheStageEndpointsRemain(): void
    {
        $routes = $this->container()->get(Router::class)->getStaticRoutes();
        self::assertArrayNotHasKey('POST:/v1/admin/regions/preview', $routes);
        self::assertArrayHasKey('POST:/v1/admin/regions/preview/session', $routes);
        self::assertArrayHasKey('POST:/v1/admin/regions/preview/apply', $routes);
    }

    public function testNoRegionPreviewTemplateResolves(): void
    {
        $loader = $this->container()->get(TwigFactory::class)->environment()->getLoader();
        self::assertFalse($loader->exists('region-preview.twig'));
        self::assertTrue($loader->exists('region-stage.twig'));
    }
}
