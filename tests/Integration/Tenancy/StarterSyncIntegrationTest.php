<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Regions\RegionRepository;
use App\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\Contracts\TenantStarterSync;

final class StarterSyncIntegrationTest extends RetrofittedTenantTestCase
{
    public function testCustomizedDefinitionIsRecordedAndNeverOverwritten(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $this->container()->get(RegionRepository::class)->save(
                'header',
                [['id' => 'customheader', 'type' => 'rich_text', 'data' => ['body' => '<p>Custom</p>']]],
                ['width' => 'contained'],
                null,
            );
        });

        $report = $this->container()->get(TenantStarterSync::class)->sync(self::$tenantAUuid);

        self::assertContains('skipped_customized', array_column($report, 'action'));
        $state = $this->runAsTenant(self::$tenantAUuid, fn() => $this->connection()
            ->table('starter_provenance')
            ->where('source_id', '=', 'region:header')
            ->first());
        self::assertSame('customized', $state['state']);
        $header = $this->runAsTenant(
            self::$tenantAUuid,
            fn() => $this->container()->get(RegionRepository::class)->find('header'),
        );
        self::assertSame('rich_text', $header['blocks'][0]['type']);
    }
}
