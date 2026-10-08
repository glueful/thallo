<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Thallo\Commerce\Shop\ProductGrid;

/**
 * The render pack builds its Twig extension, and with it the grid seam, on every page render —
 * also when the commerce engine is not loaded. The grid resolves the engine on first use, so
 * building it never fails, and without the engine it has nothing to show.
 */
final class ProductGridEngineAbsentTest extends TestCase
{
    public function testWithoutTheEngineTheGridBuildsAndShowsNothing(): void
    {
        $empty = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new class ("{$id} is not bound") extends \RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $grid = new ProductGrid($empty, new ApplicationContext(dirname(__DIR__, 3), 'testing'));
        self::assertNull($grid->grid(['source' => 'all']));
    }
}
