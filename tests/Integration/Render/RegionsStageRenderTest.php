<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\RegionStageSnapshots;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\RegionPreviewStore;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\Controllers\RegionPreviewController;
use Thallo\Core\Http\DTOs\ApplyRegionsData;
use Thallo\Core\Http\DTOs\RegionSessionData;
use Thallo\Core\Http\DTOs\SaveRegionsData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\MintsRegionTokens;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The header & footer stage render (regions-stage spec §4.4): the picked page's PUBLISHED version,
 * with the header and footer served from the session's snapshot — working copy, else baseline,
 * never the live rows — annotated for the stage, and the page body untagged.
 */
final class RegionsStageRenderTest extends AppTestCase
{
    use MintsRegionTokens;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function seedBlockTypes(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
    }

    /**
     * A published page whose draft has moved on since.
     *
     * @param list<array<string,mixed>> $body
     * @param array<string,mixed> $presentation
     */
    private function seedPage(array $body, array $presentation = [], string $slug = 'stagepage'): string
    {
        $types = new ContentTypeRepository($this->connection());
        $existing = $types->findBySlug('page');
        $type = $existing !== null ? (string) $existing['uuid'] : $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $fields = ['title' => 'S', 'body' => $body]
            + ($presentation === [] ? [] : ['_presentation' => $presentation]);
        $entries->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        // The draft moves on: the stage must not show it.
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'draftonly001', 'type' => 'rich_text', 'data' => ['body' => '<p>Draft only</p>']],
        ]], 1, 1, 'user00000001');
        return $entry;
    }

    /** @return list<array<string,mixed>> */
    private function note(string $id, string $text): array
    {
        return [['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$text}</p>"]]];
    }

    private function saveRegions(string $header, string $footer): void
    {
        $repo = new RegionRepository($this->connection());
        $repo->save('header', $this->note('hdr000000001', $header), [], null);
        $repo->save('footer', $this->note('ftr000000001', $footer), [], null);
    }

    /** @return array<string,mixed> */
    private function mint(?string $page = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(RegionSessionData::class, ['page' => $page]);
        $resp = $this->container()->get(RegionPreviewController::class)->session($dto);
        return json_decode((string) $resp->getContent(), true)['data'];
    }

    /** @param array<string,mixed> $regions @return array<string,mixed> */
    private function apply(string $token, array $regions, ?string $epoch = null, ?int $base = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(ApplyRegionsData::class, [
            'token' => $token, 'regions' => $regions, 'epoch' => $epoch, 'base_revision' => $base,
        ]);
        $resp = $this->container()->get(RegionPreviewController::class)->apply($dto);
        return json_decode((string) $resp->getContent(), true)['data'] ?? [];
    }

    private function stage(string $token, bool $canvas = true): string
    {
        $uri = "/_preview/{$token}" . ($canvas ? '?canvas=1' : '');
        return (string) $this->container()->get(RenderController::class)
            ->preview(Request::create($uri, 'GET'), $token)->getContent();
    }

    /** @return array<string,mixed> */
    private function doc(string $header, string $footer): array
    {
        return [
            'header' => ['blocks' => $this->note('hdr000000001', $header), 'settings' => []],
            'footer' => ['blocks' => $this->note('ftr000000001', $footer), 'settings' => []],
        ];
    }

    public function testTheChromeIsAnnotatedAndTheBodyIsPublishedAndUntagged(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Published body'));
        $this->saveRegions('Saved header', 'Saved footer');
        $html = $this->stage($this->mint($page)['token']);

        self::assertStringContainsString('data-thallo-block="hdr000000001"', $html);
        self::assertStringContainsString('data-thallo-block="ftr000000001"', $html);
        self::assertStringContainsString('data-thallo-slot="header"', $html);
        self::assertStringContainsString('data-thallo-slot="footer"', $html);
        self::assertStringContainsString('data-thallo-edit-block="hdr000000001"', $html);
        // The body: published, and inert — no ids, no slots, no edit regions.
        self::assertStringContainsString('Published body', $html);
        self::assertStringNotContainsString('Draft only', $html);
        self::assertStringNotContainsString('data-thallo-block="blockone0001"', $html);
        self::assertStringNotContainsString('data-thallo-slot="body"', $html);
        self::assertStringNotContainsString('data-thallo-edit-block="blockone0001"', $html);
        self::assertStringContainsString('data-thallo-canvas="regions"', $html);
    }

    public function testTheStageShowsItsSessionNeverTheLiveRows(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'));
        $this->saveRegions('Baseline header', 'Footer');
        $session = $this->mint($page);

        // Another editor saves between this session's mint and its first render.
        (new RegionRepository($this->connection()))
            ->save('header', $this->note('hdr000000001', 'Someone else'), [], null);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('Baseline header', $html);
        self::assertStringNotContainsString('Someone else', $html);

        // The working copy wins once there is one.
        $accepted = $this->apply($session['token'], $this->doc('Working header', 'Footer'));
        self::assertStringContainsString('Working header', $this->stage($session['token']));

        // This editor's save (expected against the rows now stored) clears the copy on the exact
        // pair; the stage then shows the committed snapshot.
        $repo = new RegionRepository($this->connection());
        $dto = (new RequestDataHydrator())->hydrate(SaveRegionsData::class, [
            'token' => $session['token'],
            'regions' => $this->doc('Committed header', 'Footer'),
            'expected' => [
                'header' => $repo->find('header')['lock_version'],
                'footer' => $repo->find('footer')['lock_version'],
            ],
            'preview_revision' => ['epoch' => $accepted['epoch'], 'revision' => $accepted['revision']],
        ]);
        $this->container()->get(RegionAdminController::class)->saveAll($dto);
        $after = $this->stage($session['token']);
        self::assertStringContainsString('Committed header', $after);
        self::assertStringNotContainsString('Working header', $after);
    }

    public function testOneRenderReadsOneSnapshot(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'));
        $this->saveRegions('Header', 'Footer');
        $session = $this->mint($page);
        $accepted = $this->apply($session['token'], $this->doc('Applied', 'Footer'));

        $real = $this->container()->get(RegionStageSnapshots::class);
        $spy = new class ($real) implements RegionStageSnapshots {
            public int $calls = 0;

            public function __construct(private readonly RegionStageSnapshots $inner)
            {
            }

            public function snapshot(string $session): ?array
            {
                $this->calls++;
                return $this->inner->snapshot($session);
            }
        };
        $controller = $this->container()->get(RenderController::class);
        $property = new \ReflectionProperty($controller, 'regionStage');
        $previous = $property->getValue($controller);
        $property->setValue($controller, $spy);
        try {
            $html = $this->stage($session['token']);
        } finally {
            $property->setValue($controller, $previous);
        }
        self::assertSame(1, $spy->calls);
        self::assertStringContainsString('data-thallo-revision="' . $accepted['revision'] . '"', $html);
        self::assertStringContainsString('data-thallo-epoch="' . $accepted['epoch'] . '"', $html);
    }

    public function testAnExpiredSessionRendersTheExpiredPageAndNoChrome(): void
    {
        $this->seedBlockTypes();
        $this->saveRegions('Header', 'Footer');
        $html = $this->stage($this->regionToken('nosession999'));
        self::assertStringContainsString('data-thallo-session-expired', $html);
        self::assertStringNotContainsString('data-thallo-block', $html);
        self::assertStringNotContainsString('Header', $html);
    }

    public function testAnEmptyRegionIsAnEmptySlotOnTheStageAndTheFallbackOffIt(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'));
        $this->saveRegions('Header', 'Footer');
        $session = $this->mint($page);
        $this->apply($session['token'], [
            'header' => ['blocks' => [], 'settings' => []],
            'footer' => ['blocks' => [], 'settings' => []],
        ]);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-slot="header"', $html);
        self::assertStringContainsString('data-thallo-slot="footer"', $html);
        self::assertStringNotContainsString('class="site-name"', $html, 'the fallback replaced the empty region');
        self::assertStringContainsString('data-thallo-canvas="regions"', $html);

        // Off the stage, an empty region still falls back to the built-in chrome.
        (new RegionRepository($this->connection()))->save('header', [], [], null);
        $live = (string) $this->handle(Request::create('/page/stagepage', 'GET'))->getContent();
        self::assertStringContainsString('class="site-name"', $live);
    }

    /** @dataProvider hiddenRegions */
    public function testTheStageShowsRegionsThePageHides(array $presentation): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'), $presentation);
        $this->saveRegions('Header', 'Footer');
        $html = $this->stage($this->mint($page)['token']);
        self::assertStringContainsString('data-thallo-slot="header"', $html);
        self::assertStringContainsString('data-thallo-slot="footer"', $html);

        $live = (string) $this->handle(Request::create('/page/stagepage', 'GET'))->getContent();
        foreach (['header', 'footer'] as $region) {
            if (($presentation[$region] ?? null) === 'hidden') {
                self::assertStringNotContainsString("thallo-region-{$region}", $live);
            }
        }
    }

    /** @return array<string, array{array<string,string>}> */
    public static function hiddenRegions(): array
    {
        return [
            'header hidden' => [['header' => 'hidden']],
            'footer hidden' => [['footer' => 'hidden']],
            'both hidden' => [['header' => 'hidden', 'footer' => 'hidden']],
        ];
    }

    public function testTheCanvasMarkerIsOnEveryCanvasAndNowhereElse(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'));
        $this->saveRegions('Header', 'Footer');

        $session = $this->mint($page);
        $empty = ['blocks' => [], 'settings' => []];
        $this->apply($session['token'], ['header' => $empty, 'footer' => $empty]);
        $stage = $this->stage($session['token']);
        self::assertMatchesRegularExpression('~<html[^>]*data-thallo-canvas="regions"~', $stage);

        $entryToken = $this->container()->get(PreviewMinter::class)->mint($page, 'en');
        self::assertMatchesRegularExpression('~<html[^>]*data-thallo-canvas="entry"~', $this->stage($entryToken));
        self::assertStringNotContainsString('data-thallo-canvas', $this->stage($entryToken, false));
        $live = (string) $this->handle(Request::create('/page/stagepage', 'GET'))->getContent();
        self::assertStringNotContainsString('data-thallo-canvas', $live);
    }

    public function testAPageWithAMissingBlockTypeStillLoadsAsAStage(): void
    {
        $this->seedBlockTypes();
        // A block type that existed when the page was published, deleted since.
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'retired_block',
            'label' => 'Retired',
            'schema' => [['name' => 'note', 'type' => 'string']],
        ]);
        $page = $this->seedPage([['id' => 'gone00000001', 'type' => 'retired_block', 'data' => ['note' => 'x']]]);
        $this->connection()->table('block_types')->where('slug', '=', 'retired_block')->forceDelete();
        $this->saveRegions('Header', 'Footer');
        $resp = $this->container()->get(RenderController::class)
            ->preview(Request::create('/_preview/x?canvas=1', 'GET'), $this->mint($page)['token']);
        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('data-thallo-block="hdr000000001"', (string) $resp->getContent());
    }

    public function testAnEntryCanvasStillRendersTheChromeUntagged(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Body'));
        $this->saveRegions('Header', 'Footer');
        $html = $this->stage($this->container()->get(PreviewMinter::class)->mint($page, 'en'));
        self::assertStringContainsString('data-thallo-block="draftonly001"', $html, 'the entry body is annotated');
        self::assertStringNotContainsString('data-thallo-block="hdr000000001"', $html);
        self::assertStringNotContainsString('data-thallo-slot="header"', $html);
    }

    public function testAPageWhoseTemplateFailsStillLoadsAsAStageWithItsRevision(): void
    {
        $this->seedBlockTypes();
        $page = $this->seedPage($this->note('blockone0001', 'Published body'));
        $this->saveRegions('Saved header', 'Saved footer');
        // The template repository commits on its own connection: the override is removed again
        // whatever happens, or it would break every later page render.
        $templates = new \Thallo\Render\Templates\TemplateRepository($this->connection());
        $row = $templates->save('default', 'entry/page.twig', "{% include 'partials/no-such-partial.twig' %}", null);
        $loader = $this->container()->get(\Thallo\Render\TwigFactory::class)->environment()->getLoader();
        self::assertInstanceOf(\Thallo\Render\Templates\RenderTemplateLoader::class, $loader);
        $loader->resetForRender();
        try {
            $session = $this->mint($page);
            $this->apply($session['token'], $this->doc('Stage header', 'Stage footer'));
            $response = $this->container()->get(RenderController::class)
                ->preview(Request::create("/_preview/{$session['token']}?canvas=1", 'GET'), $session['token']);
            $html = (string) $response->getContent();
        } finally {
            $uuid = (string) ($row['uuid'] ?? $templates->find('default', 'entry/page.twig')['uuid'] ?? '');
            $this->connection()->table('render_template_versions')->where('template_uuid', $uuid)->delete();
            $this->connection()->table('render_templates')->where('uuid', $uuid)->delete();
            $loader->resetForRender();
        }

        // The placeholder body, the chrome editable, and the pair the bridge checks patches against.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Stage header', $html);
        self::assertStringContainsString('data-thallo-slot="header"', $html);
        self::assertStringContainsString('thallo-region-preview__placeholder', $html);
        self::assertMatchesRegularExpression('/data-thallo-revision="1"/', $html);
    }

    public function testAPageUnpublishedAfterTheSessionFallsBackToTheHomepage(): void
    {
        $this->seedBlockTypes();
        $home = $this->seedPage($this->note('homebody0001', 'Home body'), [], 'home');
        $page = $this->seedPage($this->note('pagebody0001', 'Page body'), [], 'other');
        $this->container()->get(\Thallo\Core\Settings\SettingsStore::class)->putMany(['homepage_entry' => $home]);
        $this->saveRegions('Saved header', 'Saved footer');
        $session = $this->mint($page);
        self::assertStringContainsString('Page body', $this->stage($session['token']));

        $this->container()->get(PublishService::class)->unpublish($page, 'en');
        $html = $this->stage($session['token']);
        self::assertStringContainsString('Home body', $html);
        self::assertStringNotContainsString('Page body', $html);
        self::assertStringContainsString('data-thallo-slot="header"', $html);
    }
}
