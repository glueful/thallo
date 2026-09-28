<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Application;
use Glueful\Cache\CacheStore;
use Glueful\Validation\RequestDataHydrator;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * Listing and archive layouts on their stage (type layouts plan B, B5): the session's working copy in
 * the listing or archive frame around the page the site serves — the listing's first page, or the
 * chosen term's archive — with the card's blocks selectable once, on the first card; the other cards
 * are copies. With nothing to sample, or once the type is unlisted, the placeholder page: one sample
 * card, named, nothing written. The frame's presentation is the live page's.
 */
final class ListingLayoutStageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const FRAMED_THEME = 'listframed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->seeded = (new ListingPageSeed($this->container(), $this->appContext()))->seed();
    }

    /** @var array{post_type: string, category_type: string, pottery: string, posts: array<string,string>} */
    private array $seeded;

    protected function tearDown(): void
    {
        foreach ([['listing', 'post'], ['listing', 'note'], ['archive', 'post:categories']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->removeTheme();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function session(string $surface, string $target, ?ContainerInterface $container = null): array
    {
        $response = ($container ?? $this->container())->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => $target],
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /**
     * The surface's starter with ids, a heading added, as the working copy.
     *
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $extra blocks added after the marker
     * @return list<array<string,mixed>>
     */
    private function applyWorking(
        string $surface,
        string $target,
        string $token,
        array $settings = [],
        ?ContainerInterface $container = null,
        array $extra = [],
    ): array {
        $blocks = self::withIds([
            ...$this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter($target),
            ...$extra,
        ]);
        $blocks[] = [
            'id' => 'stagemarker1', 'type' => 'heading', 'data' => ['text' => 'WORKING-MARKER'], 'settings' => [],
        ];
        $response = ($container ?? $this->container())->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => $settings],
            ]),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return $blocks;
    }

    private function stage(string $token, ?ContainerInterface $container = null): string
    {
        $response = ($container ?? $this->container())->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 400));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        return (string) $response->getContent();
    }

    /** @return list<string> every block id in a tree */
    private static function ids(array $tree): array
    {
        $ids = [];
        foreach ($tree as $block) {
            $ids[] = $block['id'];
            foreach ($block['data'] as $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $ids = [...$ids, ...self::ids($value)];
                }
            }
        }
        return $ids;
    }

    public function testTheStageRendersTheListingsFirstPageAroundTheWorkingCopy(): void
    {
        $session = $this->session('listing', 'post');
        self::assertFalse($session['placeholder']);
        self::assertSame('Page 1', $session['sample']['label']);
        $blocks = $this->applyWorking('listing', 'post', $session['token']);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-canvas="layout"', $html);
        self::assertStringContainsString('thallo-layout--listing', $html);
        self::assertStringContainsString('WORKING-MARKER', $html);
        self::assertStringContainsString('The kiln at dawn', $html);
        self::assertStringContainsString('Glazing by hand', $html);
        self::assertStringNotContainsString('First firing', $html, 'page 2\'s');
        foreach (self::ids($blocks) as $id) {
            self::assertSame(1, substr_count($html, 'data-thallo-block="' . $id . '"'), "{$id} is selectable, once");
        }
        self::assertSame(1, substr_count($html, 'data-thallo-slot="card"'), 'the first card is the card slot');
        self::assertSame(1, substr_count($html, 'data-thallo-card-copy'), 'the second card is a copy');
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
    }

    public function testAnArchiveSessionRendersTheChosenTerm(): void
    {
        $session = $this->session('archive', 'post:categories');
        self::assertFalse($session['placeholder']);
        self::assertSame('Pottery', $session['sample']['label']);
        // The starter is today's archive page; its Term description is added from the palette.
        $this->applyWorking('archive', 'post:categories', $session['token'], extra: [
            ['type' => 'term_description', 'data' => [], 'settings' => []],
        ]);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('thallo-layout--archive', $html);
        self::assertMatchesRegularExpression('~thallo-block-listing_title[^"]*"[^>]*>Pottery</h1>~', $html);
        self::assertStringContainsString('Wheel-thrown and hand-built work from the studio.', $html);
        self::assertStringContainsString('The kiln at dawn', $html);
        self::assertStringContainsString('WORKING-MARKER', $html);
    }

    public function testAListingWithNoMembersOpensOnThePlaceholder(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'note', 'name' => 'Notes', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
        ]);
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post', 'note']]);
        $before = $this->connection()->table('entries')->count();

        $session = $this->session('listing', 'note');
        self::assertTrue($session['placeholder']);
        self::assertNull($session['sample']);
        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $html);
        self::assertStringContainsString('No published notes yet — showing a placeholder', $html);
        self::assertStringContainsString('Sample note', $html);
        self::assertSame(1, substr_count($html, 'data-thallo-slot="card"'), 'one placeholder card, annotated');
        self::assertStringNotContainsString('data-thallo-card-copy', $html);
        self::assertSame($before, $this->connection()->table('entries')->count(), 'nothing written');
    }

    public function testUnlistingMidSessionRendersThePlaceholderWithTheWorkingCopyIntact(): void
    {
        $session = $this->session('listing', 'post');
        $this->applyWorking('listing', 'post', $session['token']);
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => []]);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('No published posts yet — showing a placeholder', $html);
        self::assertStringContainsString('Sample post', $html);
        self::assertStringContainsString('WORKING-MARKER', $html, 'the working copy is intact');
        self::assertStringNotContainsString('The kiln at dawn', $html);
    }

    /**
     * Every post unpublished mid-session (final review): the listing still answers, with nothing on
     * it, and the stage falls back to the placeholder — one sample card to design, named — rather than
     * an Entry list with no card to edit. The working copy is intact.
     */
    public function testAListingEmptiedMidSessionRendersThePlaceholder(): void
    {
        $session = $this->session('listing', 'post');
        $this->applyWorking('listing', 'post', $session['token']);
        $publisher = $this->container()->get(\Thallo\Core\Content\Services\PublishService::class);
        foreach ($this->seeded['posts'] as $post) {
            $publisher->unpublish($post, 'en');
        }
        $page = $this->handle(Request::create('/post', 'GET'));
        self::assertSame(200, $page->getStatusCode(), 'the page still answers');

        $html = $this->stage($session['token']);
        self::assertStringContainsString('No published posts yet — showing a placeholder', $html);
        self::assertSame(1, substr_count($html, 'data-thallo-slot="card"'), 'one card to design');
        self::assertStringContainsString('Sample post', $html);
        self::assertStringContainsString('WORKING-MARKER', $html, 'the working copy is intact');
    }

    /**
     * The stage presents the page as the site does: the theme's settings under the layout's Frame. A
     * theme that makes every page full width shows it full width on both; a Frame that hides the
     * header hides it on both.
     */
    public function testTheStagesFramePresentationIsTheSites(): void
    {
        ThemeFixture::write($this->themeDir(), self::FRAMED_THEME, ['settings' => ['layout' => 'full']]);
        $app = self::bootAppWithConfigOverride('render', ['theme' => self::FRAMED_THEME]);
        $container = $app->getContainer();

        $blocks = self::withIds($this->container()->get(LayoutSurfaceRegistry::class)->get('listing')->starter('post'));
        $frame = ['header' => 'hidden'];
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            'listing',
            'post',
            fn (): int => $repo->saveExpected('listing', 'post', $blocks, $frame, 0, null),
        );
        $container->get(LayoutResolver::class)->forget('listing', 'post');
        $container->get(CacheStore::class)->deletePattern('render:*');
        $live = (string) (new Application($app))->handle(Request::create('/post', 'GET'))->getContent();

        $session = $this->session('listing', 'post', $container);
        $this->applyWorking('listing', 'post', $session['token'], $frame, $container);
        $stage = $this->stage($session['token'], $container);

        foreach (['live' => $live, 'stage' => $stage] as $where => $html) {
            self::assertMatchesRegularExpression(
                '~<main id="main"[^>]*class="layout--full~',
                $html,
                "{$where}: the theme's width",
            );
            self::assertStringNotContainsString('<header class="site-header', $html, "{$where}: the Frame's header");
        }
    }

    public function testARetiredSessionRendersTheRemovalPage(): void
    {
        $session = $this->session('listing', 'post');
        $parts = explode('.', (string) $session['token'], 2);
        $id = (string) json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true)['s'];
        $this->container()->get(LayoutPreviewStore::class)->retire($id, time() + 600);
        self::assertStringContainsString('data-thallo-session-retired', $this->stage($session['token']));
    }

    private function themeDir(): string
    {
        return $this->appContext()->getBasePath() . '/themes/' . self::FRAMED_THEME;
    }

    private function removeTheme(): void
    {
        $dir = $this->themeDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 's'): array
    {
        foreach ($tree as $i => $block) {
            $tree[$i]['id'] = str_pad($prefix . $i, 12, '0');
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $tree[$i]['data'][$key] = self::withIds($value, $prefix . $i . 'n');
                }
            }
        }
        return $tree;
    }
}
