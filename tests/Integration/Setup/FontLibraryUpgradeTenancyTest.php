<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Core\Content\Fonts\FontLibraryUpgrade;
use Thallo\Core\Content\Fonts\FontLibraryUpgradeStep;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Provision's font library step visits every workspace, under real tenancy enforcement: each moves
 * its own Custom uploads into its own library, with its own marker (block typeface spec §2.7).
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class FontLibraryUpgradeTenancyTest extends RetrofittedTenantTestCase
{
    private function uploadAndChoose(string $tenant, string $blob): void
    {
        $this->runAsSystem(fn () => $this->connection()->table('blobs')->insert([
            'uuid' => $blob, 'name' => $blob . '.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/' . $blob . '.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]));
        $this->runAsTenant($tenant, function () use ($blob): void {
            $this->connection()->table('media_assets')->insert([
                'blob_uuid' => $blob,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $store = $this->container()->get(SettingsStore::class);
            $store->clearCache();
            $store->putMany(['theme_font' => 'custom', 'theme_font_body' => $blob]);
        });
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?string, 2: ?string} families, text, marker */
    private function state(string $tenant): array
    {
        return $this->runAsTenant($tenant, function (): array {
            $store = $this->container()->get(SettingsStore::class);
            $store->clearCache();
            return [
                $this->connection()->table('font_families')->get(),
                $store->get('theme_font_text_family'),
                $store->get(FontLibraryUpgrade::MARKER),
            ];
        });
    }

    public function testEveryWorkspaceMovesItsOwnUploadsWithItsOwnMarker(): void
    {
        $this->uploadAndChoose(self::$tenantAUuid, 'fontblobwsa1');
        $this->uploadAndChoose(self::$tenantBUuid, 'fontblobwsb1');

        $totals = $this->runAsSystem(fn () => $this->container()->get(FontLibraryUpgradeStep::class)->run());
        self::assertGreaterThanOrEqual(2, $totals['workspaces']);
        self::assertSame(2, $totals['created']);

        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            [$families, $text, $marker] = $this->state($tenant);
            self::assertCount(1, $families, 'one family, in its own workspace');
            self::assertSame('Site text', $families[0]['name']);
            self::assertSame($families[0]['id'], $text);
            self::assertSame('1', $marker);
        }
        // A second provision moves nothing.
        $again = $this->runAsSystem(fn () => $this->container()->get(FontLibraryUpgradeStep::class)->run());
        self::assertSame(0, $again['created']);
    }
}
