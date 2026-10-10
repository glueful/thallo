<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Http\Response;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\StyleClassController;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\Controllers\RegionPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RegionSessionData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Http\DTOs\SaveRegionsData;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\SavedSectionRights;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The palette fields every editor's load and save responses carry (custom palette plan Task 12):
 * a load's generation read consistently with its document; a save's rewrites, the generation it held
 * and the replacement records newer than the editor's boundary.
 */
final class EditorPaletteFieldsTest extends AppTestCase
{
    use PaletteFixtures;
    use SyncsBlockStyleDeclarations;

    private const REWRITE = ['from' => 'color.brand-1', 'to' => 'color.accent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->configure(1, 'Gold', '#8a6a2a');
    }

    /** @return array<string,mixed> */
    private static function data(Response $res): array
    {
        return json_decode((string) $res->getContent(), true)['data'] ?? [];
    }

    private function hydrate(string $class, array $body): object
    {
        return (new RequestDataHydrator())->hydrate($class, $body);
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

    /** @param array<string,mixed> $data */
    private function assertSaveFields(array $data, int $through, bool $rewritten = true): void
    {
        self::assertSame($this->state()->generationNow(), $data['palette_generation']);
        self::assertSame([$through, $data['palette_generation']], [
            $data['palette_replacements']['after'], $data['palette_replacements']['through'],
        ]);
        if ($rewritten) {
            self::assertSame(self::REWRITE, array_intersect_key($data['palette_rewrites'][0] ?? [], self::REWRITE));
        } else {
            self::assertSame([], $data['palette_rewrites']);
        }
    }

    public function testRegionLoadAndSavesCarryThePaletteFields(): void
    {
        $controller = $this->container()->get(RegionAdminController::class);
        $loaded = self::data($controller->index())['palette_generation'];
        self::assertSame($this->state()->generationNow(), $loaded);
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $settings = ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]];
        $one = self::data($controller->update($this->hydrate(UpdateRegionData::class, [
            'blocks' => [],
            'settings' => $settings,
            'expected' => $this->expectedVersions(),
            'palette_through' => $loaded,
        ]), 'header'));
        $this->assertSaveFields($one, $loaded);
        $all = self::data($controller->saveAll($this->hydrate(SaveRegionsData::class, [
            'regions' => ['footer' => ['blocks' => [], 'settings' => $settings]],
            'expected' => $this->expectedVersions(),
            'palette_through' => $loaded,
        ])));
        $this->assertSaveFields($all, $loaded);
    }

    public function testTheRegionStageSessionCarriesItsGeneration(): void
    {
        $session = self::data($this->container()->get(RegionPreviewController::class)->session(
            $this->hydrate(RegionSessionData::class, []),
        ));
        self::assertSame($this->state()->generationNow(), $session['palette_generation']);
    }

    public function testLayoutSessionAndSaveCarryThePaletteFields(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'pfpost', 'name' => 'Post', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $session = self::data($this->container()->get(LayoutPreviewController::class)->session(
            $this->hydrate(LayoutSessionData::class, ['surface' => 'entry', 'target' => 'pfpost']),
        ));
        self::assertSame($this->state()->generationNow(), $session['palette_generation']);
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $res = $this->container()->get(LayoutAdminController::class)->save(
            $this->hydrate(SaveLayoutData::class, [
                'token' => $session['token'],
                'layout' => [
                    'blocks' => [[
                        'id' => 'laybody00001', 'type' => 'entry_content',
                        'data' => ['field' => 'body'], 'settings' => [],
                    ]],
                    'settings' => ['style' => ['colors' => ['surface' => self::tok('color.brand-1')]]],
                ],
                'expected_lock_version' => 0,
                'palette_through' => $session['palette_generation'],
            ]),
            Request::create('/'),
            'entry',
            'pfpost',
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSaveFields(self::data($res), $session['palette_generation']);
    }

    public function testStyleClassLoadAndSavesCarryThePaletteFields(): void
    {
        $controller = $this->container()->get(StyleClassController::class);
        $created = self::data($controller->store(
            $this->hydrate(StyleClassData::class, ['name' => 'Card', 'style' => [], 'palette_through' => 0]),
            Request::create('/'),
        ));
        $this->assertSaveFields($created, 0, rewritten: false);
        $id = (string) $created['style_class']['id'];
        $shown = self::data($controller->show(Request::create('/'), $id));
        self::assertSame($this->state()->generationNow(), $shown['palette_generation']);
        // the editor loads its class from the list: the list carries it too
        $listed = self::data($controller->index(Request::create('/')));
        self::assertSame($this->state()->generationNow(), $listed['palette_generation']);
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $updated = self::data($controller->update(
            $this->hydrate(UpdateStyleClassData::class, [
                'version' => $created['style_class']['version'],
                'style' => ['colors' => ['text' => self::tok('color.brand-1')]],
                'palette_through' => $shown['palette_generation'],
            ]),
            Request::create('/'),
            $id,
        ));
        $this->assertSaveFields($updated, $shown['palette_generation']);
    }

    public function testASavedSectionStoreCarriesThePaletteFields(): void
    {
        $through = $this->state()->generationNow();
        $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        $res = SavedSectionRights::editor($this->container())->store(
            $this->hydrate(SaveSectionData::class, [
                'name' => 'Hero', 'block' => self::heading('color.brand-1'), 'palette_through' => $through,
            ]),
            $request,
        );
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSaveFields(self::data($res), $through);
    }

    public function testASaveSendsTheRecordsNewerThanTheEditorsBoundary(): void
    {
        $controller = $this->container()->get(StyleClassController::class);
        $created = self::data($controller->store(
            $this->hydrate(StyleClassData::class, ['name' => 'Card', 'style' => []]),
            Request::create('/'),
        ));
        $shown = $controller->show(Request::create('/'), (string) $created['style_class']['id']);
        $loaded = self::data($shown)['palette_generation'];
        $this->replaceAndClear(1, 'color.accent');
        $updated = self::data($controller->update(
            $this->hydrate(UpdateStyleClassData::class, [
                'version' => $created['style_class']['version'], 'name' => 'Card 2', 'palette_through' => $loaded,
            ]),
            Request::create('/'),
            (string) $created['style_class']['id'],
        ));
        self::assertCount(1, $updated['palette_replacements']['records']);
        self::assertSame(
            ['color.brand-1' => 'color.accent', 'color.brand-1-contrast' => 'color.accent-contrast'],
            $updated['palette_replacements']['records'][0]['map']
        );
    }
}
