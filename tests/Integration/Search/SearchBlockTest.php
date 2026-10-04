<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Thallo\Search\Starter\SearchBlockTypeContributor;

/**
 * The Search block (search block spec §3.1): a real GET form to `/search`, or a link that opens one;
 * accessible names and per-block ids; scope and locale carried without JavaScript; nothing on the
 * public page when its scope is unavailable, and a placeholder that says why on the stage; and,
 * because cached pages are keyed by which features are on, it leaves cached pages when Search does.
 */
final class SearchBlockTest extends AppTestCase
{
    private ApplicationContext $on;

    protected function setUp(): void
    {
        parent::setUp();
        $this->on = self::booted(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true]],
        ));
    }

    /** Late-tier providers boot with the first request; rendering always happens inside one. */
    private static function booted(ApplicationContext $app): ApplicationContext
    {
        (new \Glueful\Application($app))->handle(Request::create('/robots.txt', 'GET'));
        return $app;
    }

    public function testTheSchema(): void
    {
        [$definition] = (new SearchBlockTypeContributor())->blockTypeDefinitions();
        self::assertSame('search', $definition->slug);
        self::assertSame('thallo.search', $definition->requiresCapability);
        self::assertSame(
            ['display', 'placeholder', 'scope', 'live_results'],
            array_column($definition->schema, 'name'),
        );
        self::assertSame(['field', 'icon'], $definition->schema[0]['enum']);
        self::assertSame('thallo-search.scopes', $definition->schema[2]['options_source']);
    }

    public function testFieldMode(): void
    {
        $html = $this->render(['display' => 'field', 'placeholder' => 'Find a fragrance']);
        self::assertStringContainsString('<form method="get" action="/search" role="search"', $html);
        self::assertStringContainsString('<label for="thallo-search-input-blk1" class="sr-only">Search</label>', $html);
        self::assertMatchesRegularExpression(
            '/<input id="thallo-search-input-blk1" name="q" type="search"[^>]*placeholder="Find a fragrance"/',
            $html,
        );
        self::assertStringContainsString('role="combobox"', $html);
        self::assertStringContainsString('aria-controls="thallo-search-list-blk1"', $html);
        self::assertStringContainsString('<input type="hidden" name="scope" value="">', $html);
        self::assertStringContainsString('<input type="hidden" name="locale" value="en">', $html);
        self::assertStringContainsString('id="thallo-search-list-blk1" role="listbox"', $html);
        self::assertStringContainsString('data-live="1"', $html);
    }

    public function testTheScriptIsFingerprintedAndEmittedOncePerPageAndTheStylesComeWithTheTheme(): void
    {
        $html = $this->render(['display' => 'field'], [['display' => 'icon']]);
        self::assertSame(
            1,
            preg_match_all('#<script defer src="/_thallo/search/search-[a-f0-9]+\.js"></script>#', $html),
            'one fingerprinted tag for two blocks: no redirect, no repeat',
        );
        self::assertStringNotContainsString('/_thallo/search/search.js', $html);
        self::assertStringNotContainsString('<link', $html, 'the styles are in the theme stylesheet already');
    }

    public function testIconMode(): void
    {
        $html = $this->render(['display' => 'icon', 'scope' => 'entries', 'live_results' => false]);
        self::assertStringContainsString('href="/search?scope=entries&amp;locale=en"', $html);
        self::assertStringContainsString('aria-label="Search"', $html);
        self::assertStringContainsString('aria-controls="thallo-search-panel-blk1"', $html);
        self::assertMatchesRegularExpression('/<div id="thallo-search-panel-blk1"[^>]*hidden/', $html);
        self::assertStringContainsString('data-live="0"', $html);
        self::assertStringContainsString('<input type="hidden" name="scope" value="entries">', $html);
    }

    public function testTwoBlocksHaveUniqueIds(): void
    {
        $html = $this->render(['display' => 'field'], [['display' => 'field']]);
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_values(array_unique($ids[1])), 'no id appears twice');
    }

    public function testAnUnavailableScopeRendersNothingInPublicAndAPlaceholderOnTheStage(): void
    {
        $searchOnly = self::booted(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true, 'thallo.commerce' => false]],
        ));
        $public = $this->render(['scope' => 'products'], [], $searchOnly);
        self::assertStringNotContainsString('thallo-block-search', $public);

        $stage = $this->render(['scope' => 'products'], [], $searchOnly, true);
        self::assertStringContainsString('thallo-field-empty', $stage);
        self::assertStringContainsString("Products search isn't available: Commerce is off.", $stage);
    }

    public function testAnEmptyIndexHidesNothing(): void
    {
        self::assertStringContainsString('thallo-block-search', $this->render(['display' => 'field']));
    }

    public function testTurningSearchOffRemovesTheHeaderBlockFromCachedPages(): void
    {
        (new RegionRepository($this->connection()))->save('header', [
            ['id' => 'hdrsearch001', 'type' => 'search', 'data' => ['display' => 'icon'], 'settings' => []],
        ], [], null);
        $page = static fn (ApplicationContext $app): string => (string) (new \Glueful\Application($app))
            ->handle(Request::create('/', 'GET'))->getContent();

        self::assertStringContainsString('thallo-block-search', $page($this->on));
        self::assertStringContainsString(
            'thallo-block-search',
            $page($this->on),
            'still there when served from the cache',
        );
        self::assertStringNotContainsString(
            'thallo-block-search',
            $page(self::bootAppWithConfigOverride('thallo', [])),
        );
        self::assertStringContainsString(
            'thallo-block-search',
            $page(self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]])),
        );
    }

    public function testTheScopeStateHelperIsAllowlisted(): void
    {
        self::assertContains('search_scope_state', \Thallo\Render\Templates\TemplatePolicy::FUNCTIONS);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $more further Search blocks on the same page
     */
    private function render(array $data, array $more = [], ?ApplicationContext $app = null, bool $stage = false): string
    {
        $container = ($app ?? $this->on)->getContainer();
        $env = $container->get(TwigFactory::class)->environment();
        $extension = $container->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setAnnotationScope($stage ? 'entry' : 'none');
        $extension->setLocale('en');
        $blocks = [['id' => 'blk1', 'type' => 'search', 'data' => $data]];
        foreach ($more as $i => $extra) {
            $blocks[] = ['id' => 'blk' . ($i + 2), 'type' => 'search', 'data' => $extra];
        }
        return $extension->blocks($env, ['entry' => null, 'site' => ['locale' => 'en', 'locales' => ['en']]], $blocks);
    }
}
