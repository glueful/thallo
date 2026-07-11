<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\TenantOracleTestCase;
use Thallo\Seo\Meta\SeoMetaRepository;

final class SeoMetaTenantScopeTest extends TenantOracleTestCase
{
    public function testUpsertIsIsolatedPerTenant(): void
    {
        $repo = $this->container()->get(SeoMetaRepository::class);
        // DISTINCT keys per tenant (isolation proof, not same-key coexistence — see harness pin).
        $this->runAsTenant(self::$tenantAUuid, fn () => $repo->upsert('entry-a-1', 'en', ['title' => 'A title']));
        $this->runAsTenant(self::$tenantBUuid, fn () => $repo->upsert('entry-b-1', 'en', ['title' => 'B title']));

        // Each tenant sees only its own row; the other tenant's entry is invisible.
        self::assertSame(
            'A title',
            $this->runAsTenant(self::$tenantAUuid, fn () => $repo->find('entry-a-1', 'en')['title']),
        );
        self::assertNull($this->runAsTenant(self::$tenantAUuid, fn () => $repo->find('entry-b-1', 'en')));
        self::assertSame(
            'B title',
            $this->runAsTenant(self::$tenantBUuid, fn () => $repo->find('entry-b-1', 'en')['title']),
        );
        self::assertNull($this->runAsTenant(self::$tenantBUuid, fn () => $repo->find('entry-a-1', 'en')));
    }
}
