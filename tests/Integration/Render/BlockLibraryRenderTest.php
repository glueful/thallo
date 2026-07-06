<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * The block-library expansion's load-bearing render matrix (spec §8) — the
 * pieces StarterTemplatesTest's sweep doesn't pin: the container's style
 * attribute (the injection surface, asserted verbatim), video-embed failure
 * modes, html verbatim output, shortcode template resolution, the logo
 * fallback chain, and per-instance group identity for faq/tabs.
 */
final class BlockLibraryRenderTest extends AppTestCase
{
    private function env(string $theme = 'default'): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator($theme, $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    /** @return array<string,mixed> the seeded container schema, parsed */
    private function containerSchema(): ContentTypeSchema
    {
        foreach (StarterBlockTypes::definitions() as $def) {
            if ($def['slug'] === 'container') {
                return ContentTypeSchema::fromArray($def['schema']);
            }
        }
        self::fail('container definition missing');
    }

    public function testContainerStyleAttributeCarriesExactlyTheFourVars(): void
    {
        $out = $this->render([[
            'id' => 'c1', 'type' => 'container',
            'data' => [
                'background_color' => '#112233',
                'overlay_color' => '#000000',
                'overlay_opacity' => 40,
                'content' => [],
            ],
        ]]);
        // Verbatim: the style attribute is THE injection surface — only the
        // spec'd CSS custom properties, built from typed validated fields.
        self::assertStringContainsString(
            'style="--container-bg: #112233; --container-overlay: #000000; --container-overlay-opacity: 0.4"',
            $out,
        );
        self::assertStringContainsString('thallo-block-container__overlay', $out);

        // No styling fields -> NO style attribute at all.
        $bare = $this->render([['id' => 'c2', 'type' => 'container', 'data' => ['content' => []]]]);
        self::assertStringNotContainsString('style=', $bare);
        self::assertStringNotContainsString('__overlay', $bare);
    }

    public function testContainerRejectsInvalidColorAndOpacityAtSave(): void
    {
        $schema = $this->containerSchema();
        try {
            (new FieldValidator())->validate($schema, ['background_color' => 'red; }body{']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('background_color', $e->errors());
        }
        try {
            (new FieldValidator())->validate($schema, ['overlay_opacity' => 250]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('overlay_opacity', $e->errors());
        }
    }

    public function testVideoEmbedBuildsIframesOnlyForParseableUrls(): void
    {
        $good = $this->render([['id' => 'v1', 'type' => 'video', 'data' => [
            'source' => 'embed', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]]]);
        self::assertStringContainsString(
            'src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"',
            $good,
        );

        foreach (['https://evil.test/watch?v=x', 'javascript:alert(1)', 'not a url'] as $bad) {
            $out = $this->render([['id' => 'v2', 'type' => 'video', 'data' => [
                'source' => 'embed', 'url' => $bad,
            ]]]);
            self::assertStringNotContainsString('<iframe', $out, $bad);
            self::assertStringNotContainsString('evil.test', $out, $bad);
        }
    }

    public function testHtmlBlockRendersVerbatim(): void
    {
        // Raw by design (trusted-editor opt-in; the type seeds DEACTIVATED —
        // rendering is a pure template convention and ignores active state,
        // so pre-existing content keeps rendering after a deactivation).
        $out = $this->render([['id' => 'h1', 'type' => 'html', 'data' => [
            'code' => '<div data-widget="x"><script>init()</script></div>',
        ]]]);
        self::assertStringContainsString('<div data-widget="x"><script>init()</script></div>', $out);
    }

    protected function tearDown(): void
    {
        $dir = $this->appContext()->getBasePath() . '/themes/testsc';
        if (is_dir($dir)) {
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
        parent::tearDown();
    }

    public function testCopyrightShortcodeRendersDynamicYear(): void
    {
        // The pack default theme SHIPS shortcodes/copyright.twig: the © symbol,
        // a server-rendered year (rolls over automatically), name from params
        // falling back to site.name, optional since-range.
        $year = date('Y');
        $out = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'cw1', 'type' => 'shortcode', 'data' => [
                'name' => 'copyright', 'params' => ['name' => 'Acme Co', 'since' => '2020'],
            ]]],
            'site' => ['name' => 'Thallo'],
        ]);
        self::assertStringContainsString("© 2020–{$year} Acme Co", $out);

        // No params: the site name from context, plain current year.
        $plain = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'cw2', 'type' => 'shortcode', 'data' => [
                'name' => 'copyright', 'params' => [],
            ]]],
            'site' => ['name' => 'Thallo'],
        ]);
        self::assertStringContainsString("© {$year} Thallo", $plain);
    }

    public function testShortcodeRendersThemeTemplateWithParamsOrNothing(): void
    {
        // The APP theme dir provides the shortcode (the primary convention:
        // themes ship shortcodes/{name}.twig; DB overrides ride the same
        // hierarchy in the full pipeline).
        // A NAMED app theme ships the shortcode (an app theme literally named
        // 'default' is never overlaid — ThemeLocator rule); every other
        // template falls back per-template to the pack default.
        $base = $this->appContext()->getBasePath() . '/themes/testsc';
        mkdir($base . '/templates/shortcodes', 0777, true);
        file_put_contents($base . '/theme.json', '{"name": "testsc"}');
        file_put_contents($base . '/templates/shortcodes/promo.twig', 'PROMO[{{ params.code }}]');

        $hit = $this->env('testsc')->createTemplate('{{ blocks(list) }}')->render(['list' => [
            ['id' => 's1', 'type' => 'shortcode', 'data' => [
                'name' => 'promo', 'params' => ['code' => 'X1'],
            ]],
        ]]);
        self::assertStringContainsString('PROMO[X1]', $hit);
        self::assertStringContainsString('data-shortcode="promo"', $hit);

        // Missing template -> empty inner, never an error.
        $miss = $this->render([['id' => 's2', 'type' => 'shortcode', 'data' => [
            'name' => 'nope', 'params' => [],
        ]]]);
        self::assertStringNotContainsString('PROMO', $miss);

        // Traversal-shaped names render NOTHING (defense in depth under the schema pattern).
        $bad = $this->render([['id' => 's3', 'type' => 'shortcode', 'data' => [
            'name' => '../entry', 'params' => [],
        ]]]);
        self::assertStringNotContainsString('data-shortcode', $bad);
    }

    public function testLogoFallsBackToTheSiteName(): void
    {
        // No site_logo setting bound in this env -> site_logo() is null; the
        // block renders the site name from the blocks() context passthrough.
        $out = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'site' => ['name' => 'Acme'],
            'list' => [['id' => 'l1', 'type' => 'logo', 'data' => ['link_home' => true]]],
        ]);
        self::assertStringContainsString('<span class="thallo-block-logo__name">Acme</span>', $out);
        self::assertStringContainsString('href="/"', $out);
        self::assertStringNotContainsString('<img', $out);
    }

    public function testLogoBlockRendersTheDarkPairOnlyWhenTheVariantIsSet(): void
    {
        // Container-wired path: EngineSiteLogoProvider over GeneralSettings +
        // the real MediaUrlResolver (public blobs only).
        $seedBlob = function (): string {
            $uuid = \Glueful\Helpers\Utils::generateNanoID();
            $this->connection()->table('blobs')->insert([
                'uuid' => $uuid, 'name' => 'logo.png', 'mime_type' => 'image/png',
                'size' => 1, 'url' => 'uploads/logo.png', 'visibility' => 'public',
                'status' => 'active', 'created_by' => 'user00000001',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return $uuid;
        };
        $store = $this->container()->get(\App\Settings\SettingsStore::class);
        $render = fn(): string => $this->render([
            ['id' => 'l1', 'type' => 'logo', 'data' => ['link_home' => true]],
        ]);

        // Light-only regression: the single un-suffixed image, no modifier.
        $light = $seedBlob();
        $store->putMany(['site_logo' => $light]);
        $out = $render();
        self::assertStringContainsString('<img class="thallo-block-logo__image" src="', $out);
        self::assertStringNotContainsString('--has-dark', $out);
        self::assertStringNotContainsString('__image--dark', $out);

        // Dark set: the modifier + the light/dark pair.
        $dark = $seedBlob();
        $store->putMany(['site_logo_dark' => $dark]);
        $out = $render();
        self::assertStringContainsString('thallo-block-logo--has-dark', $out);
        self::assertStringContainsString('thallo-block-logo__image--light', $out);
        self::assertStringContainsString('thallo-block-logo__image--dark', $out);
        self::assertStringContainsString('/blobs/' . $dark, $out);
    }

    public function testFaqAndTabsGroupsAreScopedPerBlockInstance(): void
    {
        $faq = static fn (string $id): array => ['id' => $id, 'type' => 'faq', 'data' => [
            'multiple' => false,
            'items' => [['id' => $id . 'i', 'type' => 'faq_item',
                'data' => ['question' => 'Q', 'answer' => '<p>A</p>']]],
        ]];
        $out = $this->render([$faq('faqblock0001'), $faq('faqblock0002')]);
        self::assertStringContainsString('name="faq-faqblock0001"', $out);
        self::assertStringContainsString('name="faq-faqblock0002"', $out);

        $tabs = static fn (string $id): array => ['id' => $id, 'type' => 'tabs', 'data' => [
            'items' => [['id' => $id . 't', 'type' => 'tab', 'data' => ['label' => 'L', 'content' => []]]],
        ]];
        $out = $this->render([$tabs('tabsblock001'), $tabs('tabsblock002')]);
        self::assertStringContainsString('name="tabs-tabsblock001"', $out);
        self::assertStringContainsString('name="tabs-tabsblock002"', $out);
        self::assertStringContainsString('id="tabs-tabsblock001-1"', $out);
        self::assertStringContainsString('id="tabs-tabsblock002-1"', $out);
    }

    public function testCarouselBaseIsPureScrollSnapAndLayoutLoadsBlocksJsOnce(): void
    {
        $out = $this->render([['id' => 'cr1', 'type' => 'carousel', 'data' => [
            'arrows' => true, 'dots' => true, 'autoplay' => true,
            'slides' => [['id' => 'crq', 'type' => 'quote', 'data' => ['text' => 'S']]],
        ]]]);
        // No server-side controls markup, no inline JS — data-attrs only.
        self::assertStringContainsString('data-arrows="1"', $out);
        self::assertStringNotContainsString('__prev', $out);
        self::assertStringNotContainsString('__dots', $out);
        self::assertStringNotContainsString('<script', $out);

        // The default layout loads the enhancement ONCE, deferred.
        $layout = (string) file_get_contents(
            $this->appContext()->getBasePath()
                . '/packages/thallo-render/themes/default/templates/layout.twig',
        );
        self::assertSame(1, substr_count($layout, "asset('blocks.js')"));
        self::assertStringContainsString('<script defer', $layout);
    }

    public function testSeededBlockTypesStayRegisteredWithTheHtmlOptIn(): void
    {
        // The registry honors seeded-inactive (spec §2): html exists but off.
        $repo = new BlockTypeRepository($this->connection());
        $repo->create(['slug' => 'html', 'label' => 'HTML', 'active' => false,
            'schema' => [['name' => 'code', 'type' => 'text']]]);
        $row = $repo->findBySlug('html');
        self::assertSame(0, (int) $row['active']);
    }

    public function testColumnsWidthPresetsEmitExactAllowlistedTokens(): void
    {
        $two = $this->render([[
            'id' => 'colw1', 'type' => 'columns',
            'data' => ['layout' => '2', 'widths' => '33-67',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringContainsString('thallo-block-columns--w-33-67', $two);

        // Mismatch (3-col preset on a 2-col layout): NO width token at all.
        $mismatch = $this->render([[
            'id' => 'colw2', 'type' => 'columns',
            'data' => ['layout' => '2', 'widths' => '33-33-33',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--w-', $mismatch);

        // Absent fields: byte-compatible with today's markup (no new tokens).
        $plain = $this->render([[
            'id' => 'colw3', 'type' => 'columns',
            'data' => ['layout' => '2', 'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--w-', $plain);
        self::assertStringNotContainsString('--align-', $plain);
    }

    public function testColumnsAlignEmitsTokensOnlyForNonDefaults(): void
    {
        $center = $this->render([[
            'id' => 'cola1', 'type' => 'columns',
            'data' => ['layout' => '2', 'align' => 'center',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringContainsString('thallo-block-columns--align-center', $center);

        $stretch = $this->render([[
            'id' => 'cola2', 'type' => 'columns',
            'data' => ['layout' => '2', 'align' => 'stretch',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--align-', $stretch);
    }

    public function testSocialLinksRenderBrandIconsWithAccessibleLabels(): void
    {
        $out = $this->render([[
            'id' => 'soc1', 'type' => 'social_links',
            'data' => ['items' => [
                ['id' => 'soc1a', 'type' => 'social_link',
                    'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme']],
            ]],
        ]]);
        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('fill="currentColor"', $out);
        self::assertStringContainsString('aria-label="github"', $out); // label falls back to the brand name
        self::assertStringContainsString('href="https://github.com/acme"', $out);
    }

    /** Seed 'main': about (plain), services (own url, child web, grandchild seo). */
    private function seedNavMenu(): void
    {
        $menus = $this->container()->get(\Thallo\Navigation\MenuRepository::class);
        $menu = $menus->createMenu('main', 'Main');
        $now = gmdate('Y-m-d H:i:s');
        $row = static fn (string $uuid, ?string $parent, int $pos, string $url, string $label): array => [
            'uuid' => $uuid, 'parent_uuid' => $parent, 'position' => $pos, 'kind' => 'url',
            'entry_uuid' => null, 'url' => $url, 'labels' => json_encode(['en' => $label]),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $menus->replaceTree((string) $menu['uuid'], 0, [
            $row('navitem00001', null, 0, '/about', 'About'),
            $row('navitem00002', null, 1, '/services', 'Services'),
            $row('navitem00003', 'navitem00002', 0, '/services/web', 'Web'),
            $row('navitem00004', 'navitem00003', 0, '/services/web/seo', 'SEO'),
        ]);
    }

    public function testNavigationRendersTreeWithHoverSubmenus(): void
    {
        $this->seedNavMenu();
        $out = $this->render([[
            'id' => 'nav2a', 'type' => 'navigation',
            'data' => ['menu' => 'main', 'align' => 'center', 'size' => 'lg',
                'active_style' => 'pill', 'hover_style' => 'underline'],
        ]]);
        foreach (
            [
            'thallo-block-navigation--align-center', 'thallo-block-navigation--size-lg',
            'thallo-block-navigation--active-pill', 'thallo-block-navigation--hover-underline',
            'thallo-block-navigation--reveal-hover',
            ] as $token
        ) {
            self::assertStringContainsString($token, $out);
        }
        self::assertStringContainsString('__item--parent', $out);   // services has children
        self::assertStringNotContainsString('<details', $out);      // hover mode
        self::assertStringContainsString('href="/services/web/seo"', $out); // grandchild FLATTENED in
        self::assertStringContainsString('<svg', $out);             // chevron-down indicator default
    }

    public function testNavigationClickModeUsesDetailsAndRepeatsParentUrl(): void
    {
        $this->seedNavMenu();
        $out = $this->render([[
            'id' => 'nav2b', 'type' => 'navigation',
            'data' => ['menu' => 'main', 'submenu_trigger' => 'click', 'submenu_icon' => 'none'],
        ]]);
        self::assertStringContainsString('<details class="thallo-block-navigation__details" name="nav-nav2b"', $out);
        // Parent url repeated as first child (summary swallows navigation).
        self::assertStringContainsString('href="/services"', $out);
        self::assertStringNotContainsString('<svg', $out);          // icon: none
    }

    public function testNavigationActiveStateMatchesCurrentPath(): void
    {
        $this->seedNavMenu();
        $active = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'nav2c', 'type' => 'navigation', 'data' => ['menu' => 'main']]],
            'current_path' => '/about',
        ]);
        self::assertStringContainsString('__item--active', $active);

        $inactive = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'nav2d', 'type' => 'navigation', 'data' => ['menu' => 'main']]],
            'current_path' => '/elsewhere',
        ]);
        self::assertStringNotContainsString('__item--active', $inactive);
    }

    public function testSocialLinkIconEnforcesBrandPrefixedStorage(): void
    {
        // P2 pin (icon-picker spec §8): the picker hiding `brand:` must never
        // be the only guard — API-written bare names 422 at the validator.
        $schema = null;
        foreach (StarterBlockTypes::definitions() as $def) {
            if ($def['slug'] === 'social_link') {
                $schema = ContentTypeSchema::fromArray($def['schema']);
            }
        }
        self::assertNotNull($schema);
        try {
            (new FieldValidator())->validate($schema, [
                'icon' => 'github', 'url' => 'https://github.com/acme',
            ]);
            self::fail('expected ValidationException for a bare brand name');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('icon', $e->errors());
        }
        $clean = (new FieldValidator())->validate($schema, [
            'icon' => 'brand:github', 'url' => 'https://github.com/acme',
        ]);
        self::assertSame('brand:github', $clean['icon']);
    }

    public function testNavigationRendersPerItemIconsWithLabelOnlyFallback(): void
    {
        $menus = $this->container()->get(\Thallo\Navigation\MenuRepository::class);
        $menu = $menus->createMenu('main', 'Main');
        $now = gmdate('Y-m-d H:i:s');
        $menus->replaceTree((string) $menu['uuid'], 0, [
            ['uuid' => 'navicon00001', 'parent_uuid' => null, 'position' => 0, 'kind' => 'url',
                'entry_uuid' => null, 'url' => '/docs', 'icon' => 'external-link',
                'labels' => json_encode(['en' => 'Docs']), 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'navicon00002', 'parent_uuid' => null, 'position' => 1, 'kind' => 'url',
                'entry_uuid' => null, 'url' => '/blog', 'icon' => 'no-such-glyph',
                'labels' => json_encode(['en' => 'Blog']), 'created_at' => $now, 'updated_at' => $now],
        ]);

        $out = $this->render([[
            'id' => 'navicn', 'type' => 'navigation',
            'data' => ['menu' => 'main', 'submenu_icon' => 'none'],
        ]]);
        self::assertStringContainsString('<svg', $out);            // external-link rendered
        self::assertStringContainsString('Docs', $out);
        self::assertStringContainsString('Blog', $out);            // unknown icon: label alone…
        self::assertStringNotContainsString('no-such-glyph', $out); // …never the raw name
    }

    public function testNavigationBlockRendersNothingForAnUnknownMenu(): void
    {
        $out = $this->render([[
            'id' => 'nav1', 'type' => 'navigation', 'data' => ['menu' => 'no-such-menu'],
        ]]);
        self::assertStringContainsString('thallo-block-navigation', $out); // root always renders
        self::assertStringNotContainsString('<nav', $out);                // but no empty nav
    }

    public function testIconBlockRendersSvgWithSizeAlignAndAccessibleLink(): void
    {
        $out = $this->render([[
            'id' => 'ic1', 'type' => 'icon',
            'data' => ['icon' => 'star', 'size' => 'large', 'align' => 'center',
                'url' => '/pricing', 'label' => 'See pricing'],
        ]]);
        self::assertStringContainsString('thallo-block-icon--large', $out);
        self::assertStringContainsString('thallo-block-icon--center', $out);
        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('aria-label="See pricing"', $out);
        self::assertStringContainsString('href="/pricing"', $out);

        // Unlinked + unknown name: no anchor, name falls back to escaped text.
        $plain = $this->render([[
            'id' => 'ic2', 'type' => 'icon', 'data' => ['icon' => 'no-such-glyph'],
        ]]);
        self::assertStringNotContainsString('<a ', $plain);
        self::assertStringNotContainsString('<svg', $plain);
        self::assertStringContainsString('no-such-glyph', $plain);
    }

    public function testFeatureIconRendersInlineSvgForLucideNames(): void
    {
        $out = $this->render([[
            'id' => 'f1', 'type' => 'feature',
            'data' => ['icon' => 'activity', 'title' => 'Fast'],
        ]]);
        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('thallo-icon', $out);
        self::assertStringNotContainsString('&lt;svg', $out); // not escaped text
    }

    public function testFeatureIconFallsBackToEscapedTextForNonNames(): void
    {
        // Legacy free-text icons (emoji) keep rendering as text…
        $emoji = $this->render([[
            'id' => 'f2', 'type' => 'feature',
            'data' => ['icon' => '✓', 'title' => 'Legacy'],
        ]]);
        self::assertStringContainsString('✓', $emoji);
        self::assertStringNotContainsString('<svg', $emoji);

        // …and a hostile legacy value is ESCAPED, never markup (Markup discipline).
        $hostile = $this->render([[
            'id' => 'f3', 'type' => 'feature',
            'data' => ['icon' => '<img src=x onerror=alert(1)>', 'title' => 'Hostile'],
        ]]);
        self::assertStringNotContainsString('<img', $hostile);
        self::assertStringContainsString('&lt;img', $hostile);
    }
}
