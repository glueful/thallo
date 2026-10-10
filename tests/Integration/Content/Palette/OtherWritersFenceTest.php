<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\StyleClassController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Content\ImportExport\ContentImporter;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionSaver;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;
use Thallo\Core\Content\Style\Classes\StyleClassJobService;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\SavedSectionRights;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;

/**
 * Custom palette spec §4.3, §4.6: regions, layouts, saved sections, style classes, the style-class
 * detach job and content import write through the palette fence.
 */
final class OtherWritersFenceTest extends AppTestCase
{
    use PaletteFixtures;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->configure(1, 'Gold', '#8a6a2a');
    }

    /** @return array<string, ?int> */
    private function expectedVersions(): array
    {
        $out = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $out[$slug] = $this->container()->get(RegionRepository::class)->find($slug)['lock_version'] ?? null;
        }
        return $out;
    }

    private function regionStyleToken(string $slug): ?string
    {
        $row = $this->container()->get(RegionRepository::class)->find($slug);
        return $row['settings']['style']['colors']['surface']['value'] ?? null;
    }

    public function testARegionSaveIsFencedAndMapsARunningReplacement(): void
    {
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $saver = $this->container()->get(RegionSaver::class);
        $saver->save(
            ['header' => ['blocks' => [], 'settings' => self::surfaceStyle('color.brand-1')]],
            $this->expectedVersions(),
            'user00000001',
        );
        self::assertSame('color.accent', $this->regionStyleToken('header'));
        self::assertSame('color.brand-1', $saver->rewrites()[0]['from'] ?? null);
    }

    public function testARegionSaveRefusesAFreshReferenceToAClearedSlot(): void
    {
        $this->clear(1);
        $this->expectException(PaletteRefusal::class);
        $this->container()->get(RegionSaver::class)->save(
            ['header' => ['blocks' => [], 'settings' => self::surfaceStyle('color.brand-1')]],
            $this->expectedVersions(),
            'user00000001',
        );
    }

    public function testALayoutSaveIsFenced(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfpost', 'name' => 'Post', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $claims = LayoutPreviewToken::verify(
            LayoutPreviewToken::mint('sess00000001', 'entry', 'pfpost', null, 'en', time() + 600, 'k'),
            'k',
            time(),
        );
        $saved = $this->container()->get(LayoutSaver::class)->save(
            $claims,
            [['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []]],
            ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]],
            0,
            null,
            'user00000001',
        );
        self::assertSame('color.accent', $saved['layout']['settings']['style']['colors']['surface']['value'] ?? null);
    }

    public function testASavedSectionIsRefusedForAClearedSlotAndMappedUnderAReplacement(): void
    {
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        $save = fn (string $name): \Glueful\Http\Response => SavedSectionRights::editor($this->container())->store(
            (new RequestDataHydrator())->hydrate(
                SaveSectionData::class,
                ['name' => $name, 'block' => self::heading('color.brand-1')],
            ),
            $request,
        );
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $res = $save('Hero');
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $row = $this->connection()->table('saved_sections')->where('name', '=', 'Hero')->first();
        self::assertStringContainsString('color.accent', (string) $row['block']);
        self::assertStringNotContainsString('color.brand-1', (string) $row['block']);
    }

    public function testAStyleClassSaveIsFenced(): void
    {
        $controller = $this->container()->get(StyleClassController::class);
        $created = $controller->store(
            (new RequestDataHydrator())->hydrate(StyleClassData::class, ['name' => 'Card', 'style' => []]),
            Request::create('/'),
        );
        $class = json_decode((string) $created->getContent(), true)['data']['style_class'];
        $this->state()->afterNextSnapshot(fn () => $this->startJob(1, 'color.accent', 'color.accent-contrast'));
        $res = $controller->update(
            (new RequestDataHydrator())->hydrate(UpdateStyleClassData::class, [
                'version' => $class['version'],
                'style' => ['hover' => ['colors' => ['text' => self::tok('color.brand-1')]]],
            ]),
            Request::create('/'),
            (string) $class['id'],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $row = $this->connection()->table('style_classes')->where('id', '=', $class['id'])->first();
        $stored = json_decode((string) $row['style'], true);
        self::assertSame('color.accent', $stored['hover']['colors']['text']['value'] ?? null);
    }

    public function testADetachCopiesClassColoursThroughTheFence(): void
    {
        $controller = $this->container()->get(StyleClassController::class);
        $created = $controller->store(
            (new RequestDataHydrator())->hydrate(StyleClassData::class, [
                'name' => 'Card', 'style' => ['colors' => ['text' => self::tok('color.brand-1')]],
            ]),
            Request::create('/'),
        );
        $classId = (string) json_decode((string) $created->getContent(), true)['data']['style_class']['id'];
        $type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfpage', 'name' => 'Page', 'schema' => [['name' => 'body', 'type' => 'blocks']],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $block = ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'x', 'level' => 'h2'],
            'settings' => ['classes' => [$classId]]];
        $entries->saveDraft($uuid, 'en', ['body' => [$block]], 1, 0, 'user00000001');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $job = $this->container()->get(StyleClassJobService::class)->queue($classId, 'detach');
        $this->container()->get(StyleClassJobRunner::class)->run($job);
        $fields = (array) $entries->findDraft($uuid, 'en')['fields'];
        self::assertSame('color.accent', $fields['body'][0]['settings']['style']['colors']['text']['value'] ?? null);
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return object
     */
    private function import(array $records): object
    {
        $dir = sys_get_temp_dir() . '/import-palette-tests';
        @mkdir($dir, 0770, true);
        $path = $dir . '/content-' . uniqid() . '.ndjson';
        $lines = array_map(static fn (array $r): string => (string) json_encode($r), $records);
        file_put_contents($path, implode("\n", $lines) . "\n");
        $job = 'job' . substr(md5($path), 0, 9);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('import_export_jobs')->insert([
            'uuid' => $job, 'type' => 'import', 'adapter' => 'thallo.content', 'status' => 'queued', 'mode' => 'commit',
            'source_disk' => 'storage', 'source_path' => 'imports/x.ndjson', 'total_records' => count($records),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'file' . substr(md5($path), 0, 8), 'job_uuid' => $job, 'role' => 'source', 'disk' => 'storage',
            'path' => $path, 'mime_type' => 'application/x-ndjson', 'size_bytes' => filesize($path) ?: 0,
            'created_at' => $now,
        ]);
        return $this->container()->get(ContentImporter::class)->process(
            new ImportBatch('batch' . substr(md5($path), 0, 7), $job, 1, 0, 50),
            new ImportContext($this->appContext(), $job, 'commit'),
        );
    }

    /** @return array{0: string, 1: string} the type and an entry with a published Accent heading */
    private function publishedAccentEntry(): array
    {
        $type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfimp', 'name' => 'Imp', 'schema' => [['name' => 'body', 'type' => 'blocks']],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['body' => [self::heading('color.accent')]], 1, 0, 'user00000001');
        $this->container()->get(PublishService::class)->publish($uuid, 'en', 'user00000001');
        return [$type, $uuid];
    }

    private function retainedVersionNaming(string $uuid, string $token): string
    {
        $versions = $this->container()->get(VersionRepository::class);
        $id = 'verhist' . substr(md5($token . $uuid), 0, 5);
        $this->connection()->table('entry_versions')->insert([
            'uuid' => $id, 'entry_uuid' => $uuid, 'locale' => 'en', 'version' => 90,
            'fields' => json_encode(['body' => [self::heading($token)]]), 'schema_version' => 1,
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
        unset($versions);
        return $id;
    }

    private function publishedToken(string $uuid): ?string
    {
        $versions = $this->container()->get(VersionRepository::class);
        $pin = $versions->findPublication($uuid, 'en');
        $v = $pin === null ? null : $versions->findVersionByUuid((string) $pin['version_uuid']);
        return $v['fields']['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
    }

    public function testAnImportRefusesARecordNamingAnUnconfiguredSlotAndKeepsTheRest(): void
    {
        [, $uuid] = $this->publishedAccentEntry();
        $result = $this->import([
            self::draftRecord($uuid, 'en', 'color.brand-3'),
            self::draftRecord($uuid, 'fr', 'color.accent'),
        ]);
        self::assertSame(1, $result->failedRecords);
        self::assertSame('palette', $result->errors[0]['code'] ?? null);
        self::assertNotNull($this->container()->get(EntryRepository::class)->findDraft($uuid, 'fr'));
    }

    public function testAnImportedPointerToAVersionNamingAJobsSourceAppendsANormalisedVersion(): void
    {
        [, $uuid] = $this->publishedAccentEntry();
        $old = $this->retainedVersionNaming($uuid, 'color.brand-1');
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $before = $this->connection()->table('entry_versions')->where('entry_uuid', '=', $uuid)->count();
        $this->import([self::pointerRecord($uuid, $old)]);
        $pinned = $this->container()->get(VersionRepository::class)->findPublication($uuid, 'en');
        self::assertNotSame($old, (string) $pinned['version_uuid'], 'never the old version as it is');
        self::assertSame('color.accent', $this->publishedToken($uuid));
        $after = $this->connection()->table('entry_versions')->where('entry_uuid', '=', $uuid)->count();
        self::assertSame($before + 1, $after);
    }

    public function testAnImportedPointerAfterTheSlotWasClearedPinsTheVersionAsARollbackWould(): void
    {
        [, $uuid] = $this->publishedAccentEntry();
        $old = $this->retainedVersionNaming($uuid, 'color.brand-1');
        $this->clear(1);
        $this->import([self::pointerRecord($uuid, $old)]);
        $pinned = $this->container()->get(VersionRepository::class)->findPublication($uuid, 'en');
        self::assertSame($old, (string) $pinned['version_uuid'], 'the version is its own basis');
    }

    public function testAnImportedVersionThatIsTheCurrentPublicationIsNormalisedAndHistoryIsNot(): void
    {
        [, $uuid] = $this->publishedAccentEntry();
        $publication = $this->container()->get(VersionRepository::class)->findPublication($uuid, 'en');
        $pinned = (string) $publication['version_uuid'];
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $this->import([
            self::versionRecord($pinned, $uuid, 1, 'color.brand-1'),
            self::versionRecord('verhist00009', $uuid, 50, 'color.brand-3'),
        ]);
        self::assertSame('color.accent', $this->publishedToken($uuid));
        $history = $this->container()->get(VersionRepository::class)->findVersionByUuid('verhist00009');
        $token = $history['fields']['body'][0]['settings']['style']['colors']['text']['value'] ?? null;
        self::assertSame('color.brand-3', $token);
    }

    /** @return array<string,mixed> */
    private static function surfaceStyle(string $token): array
    {
        return ['style' => ['colors' => ['surface' => self::tok($token)]]];
    }

    /** @return array<string,mixed> */
    private static function draftRecord(string $uuid, string $locale, string $token): array
    {
        return ['kind' => 'entry_draft', 'data' => [
            'entry_uuid' => $uuid, 'locale' => $locale, 'fields' => ['body' => [self::heading($token)]],
            'schema_version' => 1, 'lock_version' => 0,
        ]];
    }

    /** @return array<string,mixed> */
    private static function pointerRecord(string $uuid, string $version): array
    {
        return ['kind' => 'entry_publication', 'data' => [
            'entry_uuid' => $uuid, 'locale' => 'en', 'version_uuid' => $version,
        ]];
    }

    /** @return array<string,mixed> */
    private static function versionRecord(string $id, string $uuid, int $number, string $token): array
    {
        return ['kind' => 'entry_version', 'data' => [
            'uuid' => $id, 'entry_uuid' => $uuid, 'locale' => 'en', 'version' => $number,
            'fields' => ['body' => [self::heading($token)]], 'schema_version' => 1,
        ]];
    }
}
