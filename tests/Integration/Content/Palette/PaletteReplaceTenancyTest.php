<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Core\Settings\BrandColors;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Jobs\RunPaletteReplaceJob;
use Thallo\Core\Content\Palette\PaletteReplaceService;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Under real tenancy enforcement (custom palette spec §4.4): a replacement queued in one workspace
 * enters that workspace itself when a worker — which starts with no tenant — handles it, and leaves
 * every other workspace's documents and palette alone. Opt-in: THALLO_TENANCY_DEV_LINK=1.
 */
final class PaletteReplaceTenancyTest extends RetrofittedTenantTestCase
{
    /** @return array<string,mixed> */
    private static function heading(string $token): array
    {
        return ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['colors' => ['text' => ['type' => 'token', 'value' => $token]]]]];
    }

    private function sectionToken(string $name): ?string
    {
        $row = $this->connection()->table('saved_sections')->where('name', '=', $name)->first();
        $block = json_decode((string) ($row['block'] ?? '{}'), true) ?: [];
        return $block['settings']['style']['colors']['text']['value'] ?? null;
    }

    public function testAQueuedReplacementRunsInItsOwnWorkspaceOnly(): void
    {
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            $this->runAsTenant($tenant, function (): void {
                $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
                $this->container()->get(GeneralSettings::class)
                    ->save([
                        'theme_brand_colors' => BrandColors::encode([1 => new BrandSlot('Gold', '#8a6a2a')], [], 1),
                    ]);
                $this->container()->get(SavedSectionRepository::class)
                    ->create('Hero', 'Saved', null, self::heading('color.brand-1'), null, null);
            });
        }
        $job = $this->runAsTenant(
            self::$tenantAUuid,
            fn () => $this->container()->get(PaletteReplaceService::class)->start(1, 'color.accent', null, null),
        );
        // handled with NO tenant context, as a worker starts: the job enters A itself
        $this->runAsSystem(fn () => (new RunPaletteReplaceJob(
            ['job_id' => $job, 'workspace' => self::$tenantAUuid],
            $this->appContext(),
        ))->handle());
        self::assertSame('color.accent', $this->runAsTenant(self::$tenantAUuid, fn () => $this->sectionToken('Hero')));
        self::assertSame(
            'color.brand-1',
            $this->runAsTenant(self::$tenantBUuid, fn () => $this->sectionToken('Hero')),
            'B untouched',
        );
        self::assertNotNull($this->runAsTenant(self::$tenantBUuid, function () {
            $this->container()->get(GeneralSettings::class)->clearStoreCache();
            return $this->container()->get(PaletteProvider::class)->palette()->brand(1);
        }), "B's slot still configured");
        self::assertNull($this->runAsTenant(self::$tenantAUuid, function () {
            $this->container()->get(GeneralSettings::class)->clearStoreCache();
            return $this->container()->get(PaletteProvider::class)->palette()->brand(1);
        }), "A's slot cleared");
    }
}
