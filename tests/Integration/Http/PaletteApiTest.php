<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Content\Palette\Http\PaletteController;
use Thallo\Core\Tests\Support\AppTestCase;

/** The palette's admin routes (custom palette spec §4, §5): auth, content.manage, the slot range. */
final class PaletteApiTest extends AppTestCase
{
    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            ['GET', '/v1/admin/appearance/palette/brand/{slot}/usage', 'content_permission:content.manage'],
        ];
    }

    public function testEveryPaletteRouteCarriesAuthAndItsPermission(): void
    {
        foreach (self::routes() as [$method, $path, $permission]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} is registered");
            self::assertContains('auth', $route['middleware'], $path);
            self::assertContains($permission, $route['middleware'], "{$method} {$path}");
        }
    }

    public function testUsageOfASlotOutsideOneToThreeIs404(): void
    {
        $controller = $this->container()->get(PaletteController::class);
        self::assertSame(404, $controller->usage(4)->getStatusCode());
        self::assertSame(200, $controller->usage(1)->getStatusCode());
    }
}
