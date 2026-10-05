<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Glueful\Validation\RequestDataHydrator;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Fonts\FontUsage;
use Thallo\Core\Content\Fonts\Http\DTOs\AddFontFaceData;
use Thallo\Core\Content\Fonts\Http\FontLibraryController;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * Under real tenancy enforcement (plan Task 8): a workspace's usage scan counts only its own
 * documents, and the API never gives a family another workspace's file.
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class FontUsageTenancyTest extends RetrofittedTenantTestCase
{
    private const ID = 'Ab3dE5fG7hJ9';

    /** @return array<string,mixed> */
    private static function heading(): array
    {
        return ['type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['typography' => ['family' => ['type' => 'font', 'value' => self::ID]]]]];
    }

    public function testAWorkspacesUsageCountsOnlyItsOwnDocuments(): void
    {
        foreach (['A' => self::$tenantAUuid, 'B' => self::$tenantBUuid] as $name => $tenant) {
            $this->runAsTenant($tenant, function () use ($name): void {
                $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
                $this->container()->get(SavedSectionRepository::class)
                    ->create('Section ' . $name, 'Saved', null, self::heading(), null, null);
            });
        }
        // The saved sections source alone: the harness's throwaway schema predates some region columns.
        $usage = $this->runAsTenant(self::$tenantAUuid, fn () => (new FontUsage(
            $this->container()->get(BlockDocumentSources::class)->only(SavedSectionsSource::ID),
            $this->container()->get(BlockStyleRegistry::class),
            $this->connection(),
            $this->container()->get(GeneralSettings::class),
        ))->of(self::ID));
        self::assertSame(['Section A'], array_column($usage['saved_sections'], 'name'));
    }

    public function testAnotherWorkspacesFileIsRefused(): void
    {
        $this->runAsSystem(fn () => $this->connection()->table('blobs')->insert([
            'uuid' => 'fontblobownb', 'name' => 'b.woff2', 'mime_type' => 'font/woff2', 'size' => 1000,
            'url' => '/uploads/fontblobownb.woff2', 'storage_type' => 'uploads', 'visibility' => 'public',
            'status' => 'active', 'created_by' => 'user00000001', 'created_at' => date('Y-m-d H:i:s'),
        ]));
        $this->runAsTenant(self::$tenantBUuid, fn () => $this->connection()->table('media_assets')->insert([
            'blob_uuid' => 'fontblobownb',
            'created_at' => date('Y-m-d H:i:s'),
        ]));
        $now = gmdate('Y-m-d H:i:s');
        $this->runAsTenant(self::$tenantAUuid, fn () => $this->connection()->table('font_families')->insert([
            'id' => self::ID, 'name' => 'Mine', 'fallback' => 'serif', 'created_at' => $now, 'updated_at' => $now,
        ]));

        $controller = $this->container()->get(FontLibraryController::class);
        $res = $this->runAsTenant(self::$tenantAUuid, fn () => $controller->addFace(
            (new RequestDataHydrator())->hydrate(AddFontFaceData::class, ['blob_uuid' => 'fontblobownb']),
            self::ID,
        ));
        self::assertSame(422, $res->getStatusCode(), (string) $res->getContent());
        self::assertStringContainsString('That file isn\'t in the media library', (string) $res->getContent());
    }
}
