<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Settings\SettingsStore;
use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

final class StarterSeedIntegrationTest extends RetrofittedTenantTestCase
{
    public function testRepairSeedsAndActivatesOneTenantWithoutLeaking(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);

        $tenant = $this->container()->get(TenantAdministration::class)
            ->getTenant($this->appContext(), self::$tenantAUuid);
        self::assertSame('active', $tenant['status']);

        $seeded = $this->runAsTenant(self::$tenantAUuid, function (): array {
            $db = $this->connection();
            $this->container()->get(SettingsStore::class)->clearCache();
            return [
                'types' => array_column($db->table('content_types')->orderBy('slug', 'ASC')->get(), 'slug'),
                'menu' => $db->table('navigation_menus')->where('slug', '=', 'main')->first(),
                'homepage' => $this->container()->get(SettingsStore::class)->get('homepage_entry'),
                'provenance' => $db->table('starter_provenance')->get(),
                'home_route' => $db->table('entry_routes')->where('slug', '=', 'home')->first(),
                'publication' => $db->table('entry_publications')->first(),
                'home_item' => $db->table('navigation_items')->where('url', '=', '/')->first(),
            ];
        });

        self::assertSame(['category', 'pages', 'post'], $seeded['types']);
        self::assertNotNull($seeded['menu']);
        self::assertNotSame('', $seeded['homepage']);
        self::assertNotEmpty($seeded['provenance']);
        self::assertSame($seeded['homepage'], $seeded['home_route']['entry_uuid']);
        self::assertSame($seeded['homepage'], $seeded['publication']['entry_uuid']);
        self::assertNotNull($seeded['home_item']);
        self::assertNull($this->runAsTenant(
            self::$tenantBUuid,
            fn() => $this->connection()->table('content_types')->where('slug', '=', 'pages')->first(),
        ));
    }
}
