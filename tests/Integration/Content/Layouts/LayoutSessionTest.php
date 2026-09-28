<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Validation\RequestDataHydrator;
use Thallo\Contracts\Preview\PreviewFragmentRenderer;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\DTOs\ApplyPreviewData;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\Controllers\RegionPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\ApplyRegionsData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RegionSessionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * A layout's editing session (type layouts spec §5.2, §5.3, §5.5): it starts from the saved layout
 * or the starter, builds its own working copy by compare-and-set, refuses the other kinds' tokens
 * (and they refuse its), ends with its records, and — once its layout is removed — refuses every
 * apply, whichever of the two took the store's lock first.
 */
final class LayoutSessionTest extends AppTestCase
{
    use ResolvesPreviewKey;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @param list<array<string,mixed>> $schema */
    private function type(string $slug, string $name, array $schema): string
    {
        return $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => $slug, 'name' => $name, 'public_delivery' => true, 'schema' => $schema,
        ]);
    }

    private function post(): string
    {
        return $this->type('post', 'Posts', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
    }

    private function controller(): LayoutPreviewController
    {
        return $this->container()->get(LayoutPreviewController::class);
    }

    /** @return array<string,mixed> */
    private function session(string $target = 'post', ?string $sample = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => 'entry', 'target' => $target, 'sample' => $sample],
        );
        $response = $this->controller()->session($dto);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status: int, body: array<string,mixed>}
     */
    private function apply(array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, $body);
        $response = $this->controller()->apply($dto);
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>} */
    private static function layout(string $marker = 'MARKER'): array
    {
        return ['blocks' => [
            ['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ], 'settings' => []];
    }

    private static function sessionId(string $token): string
    {
        $parts = explode('.', $token, 2);
        return (string) json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true)['s'];
    }

    private function store(): LayoutPreviewStore
    {
        return $this->container()->get(LayoutPreviewStore::class);
    }

    public function testSessionStartsFromTheSavedLayoutOrTheStarter(): void
    {
        $this->post();
        $fresh = $this->session();
        self::assertTrue($fresh['starter']);
        self::assertSame(0, $fresh['layout']['lock_version']);
        self::assertSame(['entry_title', 'entry_content'], array_column($fresh['layout']['blocks'], 'type'));
        foreach ($fresh['layout']['blocks'] as $block) {
            self::assertMatchesRegularExpression('~\A[A-Za-z0-9_-]{12}\z~', $block['id']);
        }
        self::assertSame([['type' => 'entry_content', 'field' => 'body']], $fresh['required']);
        self::assertContains('entry_title', $fresh['palette']);
        self::assertTrue($fresh['placeholder']);
        self::assertNull($fresh['sample']);

        $repo = $this->container()->get(LayoutRepository::class);
        $lock = $this->container()->get(LayoutWriteLock::class);
        $blocks = self::layout()['blocks'];
        $lock->within('entry', 'post', fn (): int => $repo->saveExpected('entry', 'post', $blocks, [], 0, null));
        $saved = $this->session();
        self::assertFalse($saved['starter']);
        self::assertSame(1, $saved['layout']['lock_version']);
        // Reset to starter needs the starter whatever the baseline: every session carries it.
        self::assertSame(['entry_title', 'entry_content'], array_column($saved['starter_layout'], 'type'));
        self::assertSame('MARKER', $saved['layout']['blocks'][0]['data']['text']);

        $lock->within('entry', 'post', fn (): int => $repo->tombstone('entry', 'post', 1, null));
        $removed = $this->session();
        self::assertTrue($removed['starter']);
        self::assertSame(2, $removed['layout']['lock_version'], 'a tombstone keeps its version');
    }

    /** A surface without loops says so: the entry surface's session carries none. */
    public function testAnEntrySessionCarriesNoLoops(): void
    {
        $this->post();
        self::assertSame([], $this->session()['loops']);
    }

    public function testApplyValidatesAndAcceptsCompareAndSet(): void
    {
        $this->post();
        $token = $this->session()['token'];
        $body = ['token' => $token, 'layout' => self::layout(), 'epoch' => null, 'base_revision' => null];
        $first = $this->apply($body);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame(1, $first['body']['data']['revision']);
        self::assertNull($first['body']['data']['fragments']);

        $stale = $this->apply($body);
        self::assertSame(409, $stale['status']);
        self::assertSame(
            ['epoch' => $first['body']['data']['epoch'], 'revision' => 1],
            $stale['body']['error']['details']['current'],
        );

        $invalid = $this->apply(['token' => $token, 'layout' => ['blocks' => [], 'settings' => []],
            'epoch' => $first['body']['data']['epoch'], 'base_revision' => 1]);
        self::assertSame(422, $invalid['status']);
        self::assertArrayHasKey('blocks', $invalid['body']['error']['details']);
    }

    /**
     * The style-class guard serialises a write against a class job, so it belongs to Save: an apply
     * (every autosave) neither writes to the class row nor stalls the stage when a class it shows
     * was archived meanwhile; the Save that would persist it is refused.
     */
    public function testAnArchivedClassDoesNotStallTheStageAndSaveRefusesIt(): void
    {
        $this->post();
        $classes = $this->container()->get(\Thallo\Core\Content\Style\Classes\StyleClassRepository::class);
        $old = $classes->create(['name' => 'Old', 'style' => []]);
        $classes->archive($old['id']);
        $guardOf = fn (): int => (int) $this->connection()->table('style_classes')
            ->where('id', '=', $old['id'])->first()['reference_guard'];
        $before = $guardOf();
        $layout = self::layout();
        $layout['blocks'][0]['settings'] = ['classes' => [$old['id']]];

        $session = $this->session();
        $applied = $this->apply(['token' => $session['token'], 'layout' => $layout]);
        self::assertSame(200, $applied['status'], json_encode($applied['body']));
        self::assertSame($before, $guardOf(), 'an apply writes nothing to the class row');

        $dto = (new RequestDataHydrator())->hydrate(\Thallo\Core\Http\DTOs\SaveLayoutData::class, [
            'token' => $session['token'], 'layout' => $layout, 'expected_lock_version' => 0,
        ]);
        $saved = $this->container()->get(\Thallo\Core\Http\Controllers\LayoutAdminController::class)
            ->save($dto, Request::create('/x', 'PUT'), 'entry', 'post');
        self::assertSame(422, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertNull($this->container()->get(LayoutRepository::class)->find('entry', 'post'));
    }

    public function testTokensDoNotCross(): void
    {
        $postType = $this->post();
        $layoutToken = $this->session()['token'];
        $req = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        // A layout token opens nothing of the entry's or the regions'.
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($postType, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'T'], 1, 0, 'user00000001');
        $entryApply = $this->container()->get(EntryController::class)->applyPreview(
            new ApplyPreviewData(token: $layoutToken, fields: ['title' => 'T']),
            $req,
            $entry,
            'en',
        );
        self::assertSame(403, $entryApply->getStatusCode());
        $regionApply = $this->container()->get(RegionPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyRegionsData::class, ['token' => $layoutToken, 'regions' => []]),
        );
        self::assertSame(403, $regionApply->getStatusCode());
        $fragments = $this->container()->get(PreviewFragmentRenderer::class);
        self::assertNull($fragments->render($layoutToken, [], [], [], ['body']));

        // And theirs open nothing of a layout's.
        $entryToken = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        self::assertSame(403, $this->apply(['token' => $entryToken, 'layout' => self::layout()])['status']);
        $regions = $this->container()->get(RegionPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(RegionSessionData::class, []),
        );
        $regionToken = json_decode((string) $regions->getContent(), true)['data']['token'];
        self::assertSame(403, $this->apply(['token' => $regionToken, 'layout' => self::layout()])['status']);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function routes(): array
    {
        return [
            ['POST', '/v1/admin/layouts/preview/session'],
            ['POST', '/v1/admin/layouts/preview/apply'],
        ];
    }

    /** @dataProvider routes */
    public function testNeedsTemplatesManage(string $method, string $path): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} is not registered");
        self::assertContains('auth', $route['middleware']);
        self::assertContains('content_permission:templates.manage', $route['middleware']);
    }

    public function testExpiredRecordsAnswer410(): void
    {
        $this->post();
        // A valid token whose session has no records: they expired with it, or never existed.
        $token = LayoutPreviewToken::mint(
            'gonesession01',
            'entry',
            'post',
            null,
            'en',
            time() + 600,
            $this->previewKey($this->appContext()),
        );
        $gone = $this->apply(['token' => $token, 'layout' => self::layout()]);
        self::assertSame(410, $gone['status']);
        self::assertSame('LAYOUT_SESSION_EXPIRED', $gone['body']['error']['details']['code']);
    }

    public function testRetirementAndApplyInBothLockOrders(): void
    {
        $this->post();

        // (b) The apply lands first; retirement deletes its working copy; a later apply is refused
        // and recreates nothing.
        $b = $this->session();
        $s = self::sessionId($b['token']);
        self::assertSame(200, $this->apply(['token' => $b['token'], 'layout' => self::layout()])['status']);
        self::assertNotNull($this->store()->current($s));
        $this->store()->retire($s, time() + 600);
        self::assertNull($this->store()->current($s));
        $late = $this->apply(['token' => $b['token'], 'layout' => self::layout(),
            'epoch' => null, 'base_revision' => null]);
        self::assertSame(410, $late['status']);
        self::assertSame('LAYOUT_SESSION_RETIRED', $late['body']['error']['details']['code']);
        self::assertNull($this->store()->current($s));

        // (a) Retirement takes the store's lock first: the apply that follows is refused, and the
        // working copy never appears.
        $a = $this->session();
        $s = self::sessionId($a['token']);
        $this->store()->retire($s, time() + 600);
        $refused = $this->apply(['token' => $a['token'], 'layout' => self::layout()]);
        self::assertSame(410, $refused['status']);
        self::assertSame('LAYOUT_SESSION_RETIRED', $refused['body']['error']['details']['code']);
        self::assertNull($this->store()->current($s));
        self::assertTrue($this->store()->isRetired($s));
    }

    /**
     * Retirement and apply serialise on ONE lock — the working copy's — so only the two orders above
     * exist: while it is held, a retirement waits for it (and gives up), as an apply does.
     */
    public function testRetirementWaitsOnTheLockEveryApplyTakes(): void
    {
        $this->post();
        $session = $this->session();
        $s = self::sessionId($session['token']);
        $cache = $this->container()->get(\Glueful\Cache\CacheStore::class);
        $segment = $this->container()->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class)
            ->segment($this->appContext(), 'preview');
        $lock = $segment . 'thallo:preview:working:layout:' . $s . ':lock';
        $cache->increment($lock);
        $cache->set($lock . ':at', time(), 10);
        try {
            try {
                $this->store()->retire($s, time() + 600);
                self::fail('a retirement must wait on the held lock');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('locked', $e->getMessage());
            }
            self::assertFalse($this->store()->isRetired($s));
        } finally {
            $cache->delete($lock);
            $cache->delete($lock . ':at');
        }
    }

    public function testEveryStarterShapeOpens(): void
    {
        $this->type('category', 'Categories', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]);
        $this->type('post', 'Posts', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'body', 'type' => 'blocks', 'required' => true],
            ['name' => 'categories', 'type' => 'reference', 'multiple' => true, 'filterable' => true,
                'reference_type' => 'category', 'reference_slug_field' => 'slug'],
        ]);
        $this->type('lpages', 'Pages', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
        $this->type('guide', 'Guides', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'content', 'type' => 'blocks'],
        ]);
        $this->type('note', 'Notes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]);
        $this->type('quote', 'Quotes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'author', 'type' => 'string'],
        ]);
        foreach (['post', 'lpages', 'guide', 'note', 'quote'] as $slug) {
            $session = $this->session($slug);
            self::assertTrue($session['starter'], $slug);
            // The starter validates as it opens: applying it unchanged is accepted.
            $applied = $this->apply(['token' => $session['token'], 'layout' => $session['layout']]);
            self::assertSame(200, $applied['status'], $slug . ' ' . json_encode($applied['body']));
            $html = $this->stage($session['token']);
            self::assertStringContainsString('thallo-layout--entry', $html, $slug);
            self::assertStringContainsString('data-thallo-canvas="layout"', $html, $slug);
        }
    }

    private function stage(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        self::assertSame(200, $response->getStatusCode());
        return (string) $response->getContent();
    }
}
