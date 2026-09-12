<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Providers;

use Thallo\Core\Content\Authorization\CapabilityCatalog;
use Thallo\Core\Providers\ThalloServiceProvider;
use Glueful\Permissions\Catalog\Permission;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Thallo's capability catalog (content.manage, content.publish, tenant.*.manage, billing.manage …)
 * was never declared to the framework's permission registry, so `permissions:sync` never
 * persisted it and routes gated on `content.manage` were a hard 403 for every user, superuser
 * included. The provider now declares every catalog slug.
 */
final class ThalloProviderPermissionsTest extends TestCase
{
    public function testEveryCatalogSlugIsDeclaredWithItsLabelAndGroup(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not needed: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $declared = [];
        foreach ((new ThalloServiceProvider($container))->permissions() as $permission) {
            self::assertInstanceOf(Permission::class, $permission);
            $declared[$permission->slug()] = $permission->toArray();
        }

        $catalog = (new CapabilityCatalog())->all();
        self::assertSame(array_keys($catalog), array_keys($declared), 'one declaration per catalog slug, in order');
        self::assertSame('Manage content models', $declared['content.manage']['name'] ?? null);
        self::assertSame('Content', $declared['content.manage']['category'] ?? null);
        self::assertSame('Workspace', $declared['billing.manage']['category'] ?? null);
    }
}
