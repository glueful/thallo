<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Under real tenancy enforcement (custom palette spec §4.1): a workspace's usage scan counts only its
 * own documents. Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1.
 */
final class BrandColorUsageTenancyTest extends RetrofittedTenantTestCase
{
    public function testAWorkspacesUsageCountsOnlyItsOwnDocuments(): void
    {
        $heading = ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['colors' => ['text' => ['type' => 'token', 'value' => 'color.brand-1']]]]];
        foreach (['A' => self::$tenantAUuid, 'B' => self::$tenantBUuid] as $name => $tenant) {
            $this->runAsTenant($tenant, function () use ($name, $heading): void {
                $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
                $this->container()->get(SavedSectionRepository::class)
                    ->create('Section ' . $name, 'Saved', null, $heading, null, null);
            });
        }
        // The saved sections source alone: the harness's throwaway schema predates some region columns.
        $usage = $this->runAsTenant(self::$tenantAUuid, fn () => (new BrandColorUsage(
            $this->container()->get(BlockDocumentSources::class)->only(SavedSectionsSource::ID),
            $this->container()->get(ColorTokenWalker::class),
            $this->connection(),
        ))->of(1));
        self::assertSame(['Section A'], array_column($usage['blocking']['saved_sections'], 'name'));
    }
}
