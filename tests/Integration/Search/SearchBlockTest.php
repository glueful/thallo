<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Blocks\StarterBlockTypeSync;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
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
        // The search block's row as provision leaves it: seeded, with the shipped declaration.
        $this->on->getContainer()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->on->getContainer()->get(StarterBlockTypeSync::class)->sync();
        $this->on->getContainer()->get(BlockStyleRegistry::class)->reset();
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

    public function testWithSearchOffTheStageSaysSoOnce(): void
    {
        $off = self::booted(self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => false]]));
        $stage = $this->render(['display' => 'field'], [], $off, true);
        self::assertStringContainsString("Search isn't available: Search is off.", $stage);
        self::assertStringNotContainsString('Search search', $stage);
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
    public function testTheFieldTakesTheBlocksLookAndTheBlockKeepsItsSpacing(): void
    {
        $caps = $this->on->getContainer()->get(BlockStyleRegistry::class)->capabilitiesFor('search');
        $paths = ['colors.surface', 'colors.text', 'colors.border', 'border.width', 'radius', 'shadow'];
        foreach ([...$paths, 'typography.size'] as $path) {
            self::assertTrue($caps->allows($path), $path);
        }
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $style = [
            'colors' => ['surface' => $token('color.black'), 'border' => $token('color.accent')],
            'border' => ['width' => ['type' => 'choice', 'value' => 'thin']],
            'radius' => $token('radius.sm'),
            'spacing' => ['padding' => ['top' => ['base' => $token('spacing.lg')]]],
        ];
        $look = [
            ClassNames::for('colors.surface', 'color.black'),
            ClassNames::for('colors.border', 'color.accent'),
            ClassNames::for('border.width', 'thin'),
            ClassNames::for('radius', 'radius.sm'),
        ];
        foreach (['field', 'icon'] as $display) {
            $container = $this->on->getContainer();
            $extension = $container->get(RenderContextExtension::class);
            $extension->resetPerRenderState();
            $extension->setAnnotationScope('none');
            $extension->setLocale('en');
            $html = $extension->blocks(
                $container->get(TwigFactory::class)->environment(),
                ['entry' => null, 'site' => ['locale' => 'en', 'locales' => ['en']]],
                [[
                    'id' => 'blk1', 'type' => 'search',
                    'data' => ['display' => $display], 'settings' => ['style' => $style],
                ]],
            );
            $field = '~<input id="thallo-search-input-blk1"[^>]*class="([^"]*)"~';
            self::assertSame(1, preg_match($field, $html, $input), $html);
            self::assertSame(1, preg_match('~class="(thallo-block thallo-block-search[^"]*)"~', $html, $root));
            foreach ($look as $class) {
                self::assertStringContainsString($class, $input[1], "{$display}: {$class} on the field");
                self::assertStringNotContainsString($class, $root[1], "{$display}: {$class} not on the block");
            }
            self::assertStringContainsString(ClassNames::for('spacing.padding.top', 'spacing.lg'), $root[1]);
        }
    }

    public function testTheButtonIconAndPanelTakeTheirOwnLook(): void
    {
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $look = static fn (string $colour): array => [
            'colors' => ['surface' => $token($colour)],
            'radius' => $token('radius.sm'),
        ];
        $parts = ['button' => $look('color.black'), 'icon' => $look('color.accent'), 'panel' => $look('color.white')];
        $render = function (string $display) use ($parts): string {
            $container = $this->on->getContainer();
            $extension = $container->get(RenderContextExtension::class);
            $extension->resetPerRenderState();
            $extension->setAnnotationScope('none');
            $extension->setLocale('en');
            return $extension->blocks(
                $container->get(TwigFactory::class)->environment(),
                ['entry' => null, 'site' => ['locale' => 'en', 'locales' => ['en']]],
                [[
                    'id' => 'blk1', 'type' => 'search',
                    'data' => ['display' => $display], 'settings' => ['parts' => $parts],
                ]],
            );
        };
        $classOf = static function (string $html, string $class): string {
            $pattern = '~class="([^"]*\b' . preg_quote($class, '~') . '(?![\w-])[^"]*)"~';
            self::assertSame(1, preg_match($pattern, $html, $m), $class);
            return $m[1];
        };
        $bg = static fn (string $colour): string => ClassNames::for('colors.surface', $colour);
        $field = $render('field');
        self::assertStringContainsString($bg('color.black'), $classOf($field, 'thallo-search-form__submit'));
        $icon = $render('icon');
        self::assertStringContainsString($bg('color.black'), $classOf($icon, 'thallo-search-form__submit'));
        self::assertStringContainsString($bg('color.accent'), $classOf($icon, 'thallo-block-search__trigger'));
        self::assertStringContainsString($bg('color.white'), $classOf($icon, 'thallo-block-search__panel'));
        self::assertStringNotContainsString(
            ClassNames::for('colors.surface', 'color.black'),
            $classOf($icon, 'thallo-search-form__input'),
            "the button's look stays on the button",
        );
    }

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
