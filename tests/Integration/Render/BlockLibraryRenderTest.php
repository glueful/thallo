<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Twig\Environment;

/**
 * The block-library expansion's load-bearing render matrix (spec §8) — the
 * pieces StarterTemplatesTest's sweep doesn't pin: the container's style
 * attribute (the injection surface, asserted verbatim), video-embed failure
 * modes, html verbatim output, shortcode template resolution, the logo
 * fallback chain, and per-instance group identity for faq/tabs.
 */
final class BlockLibraryRenderTest extends LemmaTestCase
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
        self::assertStringContainsString('lemma-block-container__overlay', $out);

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
        self::assertStringContainsString('<span class="lemma-block-logo__name">Acme</span>', $out);
        self::assertStringContainsString('href="/"', $out);
        self::assertStringNotContainsString('<img', $out);
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
                . '/packages/lemma-render/themes/default/templates/layout.twig',
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

    public function testIconBlockRendersSvgWithSizeAlignAndAccessibleLink(): void
    {
        $out = $this->render([[
            'id' => 'ic1', 'type' => 'icon',
            'data' => ['icon' => 'star', 'size' => 'large', 'align' => 'center',
                'url' => '/pricing', 'label' => 'See pricing'],
        ]]);
        self::assertStringContainsString('lemma-block-icon--large', $out);
        self::assertStringContainsString('lemma-block-icon--center', $out);
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
        self::assertStringContainsString('lemma-icon', $out);
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
