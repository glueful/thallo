<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Thallo\Core\Content\Http\Controllers\SavedSectionController;
use Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternData;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\UpdateSavedSectionData;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\LayoutTypeShapes;
use Thallo\Core\Tests\Support\SavedSectionRights;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Sections saved from a layout (sections and templates design §5): they keep their field blocks,
 * normalised against the layout they came from; they are offered in every layout of their surface
 * and in no page; only the right editor saves, renames or deletes one — decided by the stored
 * row's scope; a surface that goes hides its sections and keeps them.
 */
final class SavedLayoutSectionsTest extends AppTestCase
{
    use LayoutTypeShapes;
    use SyncsBlockStyleDeclarations;

    private const COVER = [
        'id' => 'cov000000001', 'type' => 'entry_cover', 'data' => ['field' => 'cover', 'aspect' => '16:9'],
        'settings' => [],
    ];

    private const HEADING = [
        'id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'], 'settings' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->createLayoutTypeShapes();
    }

    /** @param list<string> $granted */
    private function controller(array $granted): SavedSectionController
    {
        return SavedSectionRights::controller($this->container(), $granted);
    }

    private function library(): PatternLibrary
    {
        return $this->container()->get(PatternLibrary::class);
    }

    private function repo(): SavedSectionRepository
    {
        return $this->container()->get(SavedSectionRepository::class);
    }

    private function request(): Request
    {
        $request = Request::create('https://admin.test/v1/admin/saved-sections', 'POST');
        $request->attributes->set('user', ['uuid' => 'editor000001']);
        return $request;
    }

    /** @return array<string,mixed> the response's `data.section` */
    private static function section(SymfonyResponse $res): array
    {
        return json_decode((string) $res->getContent(), true)['data']['section'];
    }

    /**
     * @param array<string,mixed> $block
     * @param list<string> $granted
     */
    private function layoutSave(array $block, array $granted = ['templates.manage']): SymfonyResponse
    {
        return $this->controller($granted)->store(
            new SaveSectionData(name: 'Cover', block: $block, scope: 'layout', surface: 'entry', target: 'lp_body'),
            $this->request(),
        );
    }

    public function testALayoutSectionKeepsItsFieldBlocksAndItsSurface(): void
    {
        $res = $this->layoutSave(self::COVER);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $section = self::section($res);
        $id = $section['id'];
        // The whole answer, built from the stored row — not looked up in the page library.
        self::assertSame($this->library()->savedEntry((array) $this->repo()->find($id)), $section);
        self::assertSame(
            ['saved-' . $id, 'section', 'Cover', 'Saved', '', 'layout', null, 'entry', null, true],
            [
                $section['slug'], $section['kind'], $section['label'], $section['category'], $section['description'],
                $section['scope'], $section['region'], $section['surface'], $section['settings'], $section['saved'],
            ],
        );
        self::assertSame('entry_cover', $section['blocks'][0]['type']);
        self::assertDataMatchesDtoShape($section, PatternData::class);

        $pageSlugs = array_column($this->library()->all(), 'slug');
        self::assertNotContains($section['slug'], $pageSlugs, 'not in the page library');
        self::assertContains(
            $section['slug'],
            array_column($this->library()->forLayout('entry', 'lp_nocover'), 'slug'),
            'offered in every layout of its surface, fitting or not',
        );
        $listingSlugs = array_column($this->library()->forLayout('listing', 'lp_body'), 'slug');
        self::assertNotContains($section['slug'], $listingSlugs);
    }

    public function testAnUnboundFieldBlockIsStoredWithTheBindingItHadWhereItWasSaved(): void
    {
        $cover = ['id' => 'cov000000002', 'type' => 'entry_cover', 'data' => ['aspect' => '16:9'], 'settings' => []];
        $id = self::section($this->layoutSave($cover))['id'];
        self::assertSame('cover', $this->repo()->find($id)['block']['data']['field'] ?? null);
    }

    public function testALayoutSectionKeepsTheLabelsOfTheFieldsItShows(): void
    {
        // Offered in a layout whose type lacks one of its fields, the section names that field by
        // the label it had where it was saved — the other type has no label for a field it lacks.
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'lp_dek', 'name' => 'Dek pages', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'dek', 'type' => 'string', 'label' => 'Subtitle'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $dek = [
            'id' => 'dek000000001', 'type' => 'entry_field', 'data' => ['field' => 'dek', 'format' => 'text'],
            'settings' => [],
        ];
        $res = $this->controller(['templates.manage'])->store(
            new SaveSectionData(name: 'Dek', block: $dek, scope: 'layout', surface: 'entry', target: 'lp_dek'),
            $this->request(),
        );
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $section = self::section($res);
        self::assertSame(['dek' => 'Subtitle'], $section['field_labels']);
        self::assertSame(['dek' => 'Subtitle'], $this->repo()->find($section['id'])['field_labels'] ?? null);
        $offered = array_column($this->library()->forLayout('entry', 'lp_body'), null, 'slug')[$section['slug']];
        self::assertSame(['dek' => 'Subtitle'], $offered['field_labels']);
        foreach ($this->library()->forLayout('entry', 'lp_body') as $entry) {
            if (!$entry['saved']) {
                self::assertNull($entry['field_labels'], "{$entry['slug']}: shipped, it binds this type's own fields");
            }
        }
    }

    public function testRenameAnswersWithTheWholeSection(): void
    {
        $id = $this->repo()->create('Cover', 'Saved', null, self::COVER, null, null, 'entry');
        $rename = new UpdateSavedSectionData(name: 'Wide cover');
        $res = $this->controller(['templates.manage'])->update($rename, $id, $this->request());
        $section = self::section($res);
        self::assertSame('Wide cover', $section['label']);
        self::assertSame($this->library()->savedEntry((array) $this->repo()->find($id)), $section);

        $page = $this->repo()->create('Hi', 'Saved', null, self::HEADING, null, null);
        $renamed = self::section(
            $this->controller(['content.manage'])->update(
                new UpdateSavedSectionData(name: 'Hello'),
                $page,
                $this->request(),
            ),
        );
        self::assertSame(['Hello', 'page', null], [$renamed['label'], $renamed['scope'], $renamed['surface']]);
    }

    public function testAClosedTargetOffersNothingEvenWithSavedSections(): void
    {
        $block = ['type' => 'listing_title', 'data' => ['level' => 'h1'], 'settings' => []];
        $this->repo()->create('Title', 'Saved', null, $block, null, null, 'listing');
        $saved = array_filter($this->library()->forLayout('listing', 'lp_body'), static fn ($e) => $e['saved']);
        self::assertCount(1, $saved);
        $this->closeListing('lp_body');
        self::assertSame([], $this->library()->forLayout('listing', 'lp_body'));
    }

    public function testALayoutSectionFollowsItsSurfacesRules(): void
    {
        $loop = ['id' => 'loop00000001', 'type' => 'entry_loop', 'data' => ['card' => []], 'settings' => []];
        self::assertSame(422, $this->layoutSave($loop)->getStatusCode(), 'an Entry list is not in the post palette');
        $unknown = $this->controller(['templates.manage'])->store(
            new SaveSectionData(name: 'X', block: self::COVER, scope: 'layout', surface: 'basket', target: 'x'),
            $this->request(),
        );
        self::assertSame(422, $unknown->getStatusCode());
        $missing = [
            'id' => 'fld000000001', 'type' => 'entry_field', 'data' => ['field' => 'subtitle'], 'settings' => [],
        ];
        self::assertSame(422, $this->layoutSave($missing)->getStatusCode(), 'a field the layout it came from lacks');
    }

    public function testSavingAsksTheScopesPermission(): void
    {
        self::assertSame(403, $this->layoutSave(self::COVER, ['content.manage'])->getStatusCode());
        $page = new SaveSectionData(name: 'Hi', block: self::HEADING);
        self::assertSame(403, $this->controller(['templates.manage'])->store($page, $this->request())->getStatusCode());
        self::assertSame(201, $this->controller(['content.manage'])->store($page, $this->request())->getStatusCode());
    }

    public function testRenameAndDeleteAskTheStoredRowsScope(): void
    {
        $layout = $this->repo()->create('Cover', 'Saved', null, self::COVER, null, null, 'entry');
        $page = $this->repo()->create('Hi', 'Saved', null, self::HEADING, null, null);
        $rename = new UpdateSavedSectionData(name: 'X');

        $contentOnly = $this->controller(['content.manage']);
        self::assertSame(403, $contentOnly->update($rename, $layout, $this->request())->getStatusCode());
        self::assertSame(403, $contentOnly->destroy($layout, $this->request())->getStatusCode());
        self::assertSame('Cover', $this->repo()->find($layout)['name'] ?? null, 'untouched');

        $templatesOnly = $this->controller(['templates.manage']);
        self::assertSame(403, $templatesOnly->update($rename, $page, $this->request())->getStatusCode());
        self::assertSame(403, $templatesOnly->destroy($page, $this->request())->getStatusCode());
        self::assertTrue($this->repo()->exists($page), 'untouched');

        self::assertSame(200, $templatesOnly->destroy($layout, $this->request())->getStatusCode());
        self::assertSame(200, $contentOnly->destroy($page, $this->request())->getStatusCode());
        self::assertSame(404, $contentOnly->destroy($page, $this->request())->getStatusCode());
    }

    public function testExistingRowsAreUnchanged(): void
    {
        $page = $this->repo()->create('Hi', 'Saved', null, self::HEADING, null, null);
        $header = $this->repo()->create('Bar', 'Saved', null, self::HEADING, 'header', null);
        $rows = array_column($this->repo()->all(), null, 'id');
        $place = static fn (array $row): array => [$row['scope'], $row['region'], $row['surface']];
        self::assertSame(['page', null, null], $place($rows[$page]));
        self::assertSame(['region', 'header', null], $place($rows[$header]));
    }

    public function testAVanishedSurfacesSectionsAreHiddenAndKept(): void
    {
        $block = ['type' => 'product_story', 'data' => [], 'settings' => []];
        $id = $this->repo()->create('Story', 'Saved', null, $block, null, null, 'product');
        $disabled = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $library = $disabled->getContainer()->get(PatternLibrary::class);
            self::assertNotContains('saved-' . $id, array_column($library->all(), 'slug'));
            try {
                $library->forLayout('product', '@site');
                self::fail('the product surface is gone with Commerce');
            } catch (\InvalidArgumentException) {
            }
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
        self::assertTrue($this->repo()->exists($id), 'kept for when the surface returns');
    }

    public function testTheRoutesLeaveTheDecisionToTheController(): void
    {
        $routes = [
            ['POST', '/v1/admin/saved-sections'],
            ['PATCH', '/v1/admin/saved-sections/{id}'],
            ['DELETE', '/v1/admin/saved-sections/{id}'],
        ];
        foreach ($routes as [$method, $path]) {
            $middleware = (array) ($this->findRoute($method, $path)['middleware'] ?? []);
            self::assertContains('content_permission:content.view', $middleware, "{$method} {$path}");
            self::assertNotContains('content_permission:content.manage', $middleware, "{$method} {$path}");
        }
    }
}
