<?php

declare(strict_types=1);

namespace Acme\Bookings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\Permission;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;

/**
 * A third-party engine: it knows Thallo only through thallo-contracts. Its capability is declared
 * in composer.json (extra.thallo.capabilities), so Thallo lists it while this provider is disabled.
 */
final class BookingsServiceProvider extends ServiceProvider
{
    public function boot(ApplicationContext $context): void
    {
        $container = $context->getContainer();
        if (!$container->has(StarterBlockTypeRegistry::class)) {
            return;
        }
        $registry = $container->get(StarterBlockTypeRegistry::class);
        foreach ($registry->all() as $contributor) {
            if ($contributor instanceof BookingBlockTypes) {
                return;
            }
        }
        $registry->register(new BookingBlockTypes());
    }

    public function permissions(): array
    {
        return [
            Permission::define('bookings.manage')
                ->label('Manage bookings')
                ->description('Manage bookings')
                ->category('bookings')
                ->resource('bookings')
                ->managedBy('acme/bookings'),
        ];
    }
}
