<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\StarterBlockTypes;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

final class StarterTemplatesTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /**
     * Renders a block list through the real container-bound RenderContextExtension,
     * resetting its per-render state first (block_script dedupe, priority-image claim,
     * block depth/frames) — a MUST for templates that call block_script()/
     * claim_priority_image(): the extension is a process-shared singleton across every
     * test in the run, so a prior test's emission would otherwise leak into this one.
     *
     * @param list<array<string,mixed>> $list
     */
    private function renderList(array $list): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => $list]);
    }

    /** Inserts a real blobs row (visibility/mime_type-driven media_image() resolution). */
    private function seedBlob(string $uuid, string $mime, string $visibility = 'public'): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'startertpl-' . $uuid,
            'mime_type' => $mime,
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => $visibility,
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Representative data per starter slug (media uuids resolve to null harmlessly).
     *
     * @return array<string,mixed>
     */
    private function fixture(string $slug): array
    {
        return match ($slug) {
            'section' => ['title' => 'Band', 'background' => 'subtle',
                'content' => [['id' => 'x1', 'type' => 'rich_text', 'data' => ['body' => '<p>Inner</p>']]]],
            'columns' => ['layout' => '2', 'widths' => '33-67', 'align' => 'center',
                'col_1' => [['id' => 'x2', 'type' => 'rich_text', 'data' => ['body' => '<p>Left</p>']]],
                'col_2' => [['id' => 'x3', 'type' => 'rich_text', 'data' => ['body' => '<p>Right</p>']]],
                'col_3' => []],
            'spacer' => ['size' => 'large'],
            'hero' => ['headline' => 'New', 'title' => 'Big', 'description' => 'Sub',
                'image' => 'blob00000000', 'orientation' => 'horizontal', 'reverse' => true,
                'links' => [['id' => 'hb1', 'type' => 'button',
                    'data' => ['label' => 'Go', 'url' => '/start']]]],
            'rich_text' => ['body' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>'],
            'heading' => ['text' => 'Section label', 'level' => 'h3', 'align' => 'center',
                'color' => '#ff0000'],
            'file' => ['file' => 'blob00000000', 'label' => 'Spec sheet', 'new_tab' => true],
            'cta' => ['title' => 'Act now', 'description' => 'Because.', 'variant' => 'solid',
                'orientation' => 'vertical',
                'links' => [['id' => 'cb1', 'type' => 'button',
                    'data' => ['label' => 'Do it', 'url' => 'https://example.com']]]],
            'image' => ['image' => 'blob00000000', 'alt' => 'A pic', 'caption' => 'Cap', 'size' => 'wide'],
            'style' => ['accent' => 'rose', 'neutral' => 'zinc', 'class_hook' => 'promo', 'content' => []],
            'container' => ['background_color' => '#112233', 'overlay_color' => '#000000',
                'overlay_opacity' => 40, 'width' => 'full', 'padding_preset' => 'large',
                'content' => [['id' => 'cq', 'type' => 'rich_text', 'data' => ['body' => '<p>Boxed</p>']]]],
            'grid' => ['columns' => '3', 'flow' => 'masonry', 'gap' => 'small',
                'items' => [['id' => 'gq', 'type' => 'rich_text', 'data' => ['body' => '<p>Cell</p>']]]],
            'feature' => ['icon' => '⚡', 'title' => 'Fast', 'description' => 'Quick.', 'url' => '/x'],
            'tabs' => ['items' => [['id' => 'tb1', 'type' => 'tab',
                'data' => ['label' => 'One', 'content' => [['id' => 'tq', 'type' => 'rich_text',
                    'data' => ['body' => '<p>Panel</p>']]]]]]],
            'tab' => ['label' => 'One', 'content' => [['id' => 'tq2', 'type' => 'rich_text',
                'data' => ['body' => '<p>Panel</p>']]]],
            'button' => ['label' => 'Click', 'url' => '/go', 'variant' => 'subtle', 'color' => 'neutral',
                'size' => 'xl', 'leading_icon' => 'arrow-right', 'trailing_icon' => 'arrow-right',
                'block' => true, 'align' => 'center'],
            'carousel' => ['slides_per_view' => '2', 'arrows' => true, 'dots' => true,
                'slides' => [['id' => 'sl1', 'type' => 'rich_text', 'data' => ['body' => '<p>Slide</p>']]]],
            'logo' => ['size' => 'large', 'link_home' => true],
            'icon' => ['icon' => 'star', 'size' => 'large', 'align' => 'center',
                'url' => '/pricing', 'label' => 'See pricing'],
            'navigation' => ['menu' => 'main', 'orientation' => 'horizontal', 'align' => 'center',
                'size' => 'md', 'submenu_trigger' => 'hover'],
            'social_links' => ['items' => [['id' => 'socfix1', 'type' => 'social_link',
                'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme', 'label' => 'GitHub']]]],
            'social_link' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme'],
            'video' => ['source' => 'embed', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
                'width' => 'wide', 'caption' => 'Watch'],
            'audio' => ['audio' => 'blob00000000', 'title' => 'Listen'],
            'html' => ['code' => '<marquee>hi</marquee>'],
            'shortcode' => ['name' => 'promo', 'params' => ['x' => 1]],
            // New primitives (theme rewrite): parents carry a representative child;
            // item carriers (accordion_item, stepper_item) render standalone too.
            'accordion' => ['title' => 'FAQ', 'multiple' => false,
                'items' => [['id' => 'ac1', 'type' => 'accordion_item',
                    'data' => ['question' => 'Why?', 'answer' => '<p>Because</p>']]]],
            'accordion_item' => ['question' => 'Why?', 'answer' => '<p>Because</p>'],
            'card' => ['icon' => 'star', 'title' => 'Card', 'description' => 'A card.',
                'variant' => 'subtle', 'orientation' => 'vertical',
                'body' => [['id' => 'cbd', 'type' => 'rich_text', 'data' => ['body' => '<p>Body</p>']]]],
            'collapsible' => ['label' => 'More', 'open' => false,
                'content' => [['id' => 'cl1', 'type' => 'rich_text', 'data' => ['body' => '<p>Hidden</p>']]]],
            'footer' => [
                'copyright' => [['id' => 'fcop', 'type' => 'shortcode',
                    'data' => ['name' => 'copyright', 'params' => []]]],
                // Footer's top band composes columns of titled link-lists from
                // primitives (columns + links) — the footer_columns block was removed.
                'top' => [['id' => 'ftop', 'type' => 'columns',
                    'data' => ['layout' => '2', 'col_1' => [['id' => 'ftc1', 'type' => 'links',
                        'data' => ['title' => 'Product',
                            'items' => [['label' => 'Pricing', 'url' => '/pricing']]]]]]]],
                'links' => [['id' => 'flnk', 'type' => 'links',
                    'data' => ['items' => [['label' => 'Home', 'url' => '/']]]]],
                'social' => [['id' => 'fsoc', 'type' => 'social_links',
                    'data' => ['items' => [['id' => 'fs1', 'type' => 'social_link',
                        'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme']]]]]]],
            'links' => ['title' => 'Links',
                'items' => [['label' => 'Home', 'url' => '/', 'icon' => 'home']]],
            'logos' => ['title' => 'Trusted by', 'images' => ['blob00000000'],
                'grayscale' => true, 'scroll' => true],
            'separator' => ['label' => 'or', 'type' => 'dashed', 'size' => 'sm', 'icon' => 'star'],
            'stepper' => ['title' => 'How', 'orientation' => 'horizontal', 'color' => 'primary',
                'size' => 'md', 'items' => [['id' => 'sp1', 'type' => 'stepper_item',
                    'data' => ['title' => 'Sign up', 'description' => 'First.']]]],
            'stepper_item' => ['title' => 'Sign up', 'description' => 'First.'],
            // modern-blocks spec §3/§4: templates land in this task; the field names are
            // Task 3's verbatim schema.
            'animated_text' => ['prefix' => 'Build', 'rotate_words' => "fast\nwell",
                'suffix' => 'with Thallo', 'effect' => 'fade', 'tag' => 'h2'],
            'gallery' => ['items' => [['id' => 'gsmoke1', 'type' => 'image',
                'data' => ['image' => 'blob00000000', 'alt' => 'Pic']]],
                'columns' => '3', 'aspect' => 'natural', 'lightbox' => true],
            default => [],
        };
    }

    public function testEveryStarterRendersWithRootAndModifierClasses(): void
    {
        $env = $this->env();
        foreach (StarterBlockTypes::definitions() as $definition) {
            $slug = $definition['slug'];
            $out = $env->createTemplate("{{ blocks(list) }}")->render(['list' => [
                ['id' => 'b1', 'type' => $slug, 'data' => $this->fixture($slug)],
            ]]);
            self::assertNotSame('', trim($out), "empty render for {$slug}");
            self::assertStringContainsString("thallo-block-{$slug}", $out, $slug);
        }
        // rich_text renders SANITIZED through safe_html — markup survives, attacks
        // never reach output (the no-|raw pin, now with the sanitizer shipped).
        $rich = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'rt', 'type' => 'rich_text', 'data' => $this->fixture('rich_text')]]]);
        self::assertStringContainsString('<strong>world</strong>', $rich);
        self::assertStringNotContainsString('<script', $rich);

        // Spot-check modifier classes (the style-convention pin).
        $section = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 's', 'type' => 'section', 'data' => $this->fixture('section')]]]);
        self::assertStringContainsString('thallo-block-section--subtle', $section);
        self::assertStringContainsString('Inner', $section); // children composed
    }

    public function testColumnsRendersPerLayoutEnum(): void
    {
        $env = $this->env();
        $two = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c', 'type' => 'columns', 'data' => $this->fixture('columns')]]]);
        self::assertStringContainsString('Left', $two);
        self::assertStringContainsString('Right', $two);
        self::assertStringContainsString('thallo-block-columns--2', $two);

        $data = $this->fixture('columns');
        $data['layout'] = '3';
        $data['col_3'] = [['id' => 'x4', 'type' => 'rich_text', 'data' => ['body' => '<p>Third</p>']]];
        $three = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'c3', 'type' => 'columns', 'data' => $data]]]);
        self::assertStringContainsString('Third', $three);
        self::assertStringContainsString('thallo-block-columns--3', $three);
    }

    public function testUnsafeUrlsRenderNoLinkThroughTheRealTemplates(): void
    {
        // The global safe_url rule (block-library spec §2): every user URL
        // field scheme-allowlists before landing in an href.
        $env = $this->env();
        $urlBlocks = ['button' => 'url', 'feature' => 'url', 'icon' => 'url', 'social_link' => 'url'];
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.com'] as $bad) {
            foreach ($urlBlocks as $slug => $field) {
                $data = $this->fixture($slug);
                $data[$field] = $bad;
                $out = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
                    ['id' => 'u', 'type' => $slug, 'data' => $data]]]);
                self::assertStringNotContainsString('<a href', $out, "{$slug} with {$bad}");
                self::assertStringNotContainsString($bad, $out, "{$slug} echoes {$bad}");
            }
        }
        // …and safe URLs do link.
        $ok = $env->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'ok', 'type' => 'button', 'data' => $this->fixture('button')]]]);
        self::assertStringContainsString('<a class="thallo-block-button__link', $ok);
    }

    public function testMediaTemplatesSkipTheImageOnUnresolvableBlobs(): void
    {
        // The suite's uploads.access default is private → media() nulls; the image
        // element must be absent while the block still renders.
        $out = $this->env()->createTemplate("{{ blocks(l) }}")->render(['l' => [
            ['id' => 'i', 'type' => 'image', 'data' => $this->fixture('image')]]]);
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('thallo-block-image', $out);
    }

    public function testHeadingUsesLevelAlignAndColorAndDefaultsToH2(): void
    {
        $render = fn(array $data): string => $this->env()->createTemplate("{{ blocks(l) }}")
            ->render(['l' => [['id' => 'h', 'type' => 'heading', 'data' => $data]]]);

        // Level → the tag; align → modifier class; color → inline style; text escaped.
        $out = $render(['text' => 'Hi', 'level' => 'h3', 'align' => 'center', 'color' => '#ff0000']);
        self::assertStringContainsString('<h3 class="thallo-block thallo-block-heading', $out);
        self::assertStringContainsString('thallo-block-heading--center"', $out);
        self::assertStringContainsString('style="color:#ff0000"', $out);
        self::assertStringContainsString('>Hi</h3>', $out);

        // No level → defaults to h2; unknown level degrades to h2 too.
        self::assertStringContainsString('<h2 ', $render(['text' => 'Plain']));
        self::assertStringContainsString('<h2 ', $render(['text' => 'X', 'level' => 'h9']));
    }

    // ---- animated_text (modern-blocks spec §3) -----------------------------------

    public function testAnimatedTextRendersRotateStackVisuallyHiddenPhraseAndVisiblePhrase(): void
    {
        $out = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text',
                'data' => ['prefix' => 'Build', 'rotate_words' => "fast\nwell", 'suffix' => 'with Thallo']],
        ]);

        // tag empty → defaults to h2.
        self::assertStringContainsString('<h2 class="thallo-block thallo-block-animated_text', $out);
        self::assertStringContainsString('data-effect="fade"', $out);

        // No aria-label anywhere — the paragraph role (and others) ignore it, so the
        // stable phrase now rides a visually-hidden span instead (assistive-tech fix).
        self::assertStringNotContainsString('aria-label=', $out);
        self::assertStringContainsString(
            '<span class="thallo-block-animated_text__sr">Build fast with Thallo</span>',
            $out,
        );

        // The entire visual assembly (prefix, rotate stack, suffix) sits in ONE
        // aria-hidden container, immediately after the visually-hidden phrase span.
        self::assertStringContainsString(
            '<span class="thallo-block-animated_text__sr">Build fast with Thallo</span><span aria-hidden="true">',
            $out,
        );

        // Rotate stack: BOTH words stacked, first one active (no longer individually
        // aria-hidden — the single outer wrapper above already covers it).
        self::assertStringContainsString('<span class="thallo-block-animated_text__rotate">', $out);
        self::assertStringContainsString(
            'thallo-block-animated_text__word thallo-block-animated_text__word--active">fast</span>',
            $out,
        );
        self::assertStringContainsString('thallo-block-animated_text__word">well</span>', $out);

        // No --prepared class ever appears server-side (JS-only concern).
        self::assertStringNotContainsString('--prepared', $out);

        // Visible phrase: drop the visually-hidden sr span (its text is off-screen, not
        // "visible") and the inactive rotate word span(s), strip tags, collapse
        // whitespace — exact concatenation of prefix + active word + suffix (no
        // whitespace-control artifacts leaking extra/missing spaces).
        $visibleOnly = preg_replace(
            '#<span class="thallo-block-animated_text__sr">.*?</span>#s',
            '',
            $out,
        );
        $visibleOnly = preg_replace(
            '#<span class="thallo-block-animated_text__word">.*?</span>#s',
            '',
            (string) $visibleOnly,
        );
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $visibleOnly)));
        self::assertSame('Build fast with Thallo', $text);
    }

    public function testAnimatedTextEmitsBlockScriptOnceForTwoInstancesInOneRender(): void
    {
        $data = ['prefix' => 'Build', 'rotate_words' => "fast\nwell", 'suffix' => 'with Thallo'];
        $out = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text', 'data' => $data],
            ['id' => 'a2', 'type' => 'animated_text', 'data' => $data],
        ]);
        self::assertSame(
            1,
            substr_count($out, '<script defer src="/_thallo/runtime/block-animated-text.js"></script>'),
        );
    }

    public function testAnimatedTextWithEmptyRotateWordsHasNoStackButStillCallsBlockScript(): void
    {
        $out = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text',
                'data' => ['prefix' => 'Build', 'rotate_words' => '', 'suffix' => 'fast']],
        ]);
        self::assertStringNotContainsString('__rotate', $out);
        self::assertStringNotContainsString('__word', $out);
        self::assertStringContainsString('Build', $out);
        self::assertStringContainsString('fast', $out);
        self::assertStringContainsString('<script defer src="/_thallo/runtime/block-animated-text.js">', $out);
    }

    public function testAnimatedTextCrlfRotateWordsMatchesValidatorNormalizationParity(): void
    {
        // THE parity assertion (modern-blocks spec §3): the validator's save-time cap
        // and the template's render-time split MUST agree on the exact same semantics.
        $raw = "a\r\nb\r\rc";
        $expected = FieldValidator::normalizeRotateWords($raw);
        self::assertSame(['a', 'b', 'c'], $expected);

        $out = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text', 'data' => ['rotate_words' => $raw]],
        ]);
        preg_match_all(
            '#<span class="thallo-block-animated_text__word[^"]*">([^<]*)</span>#',
            $out,
            $matches,
        );
        self::assertSame($expected, $matches[1]);
    }

    // ---- gallery (modern-blocks spec §4) ------------------------------------------

    public function testGalleryResolvesImagesOmitsMissingAndPdfWithFallbackLabels(): void
    {
        $this->seedBlob('gimgtest001', 'image/jpeg');
        $this->seedBlob('gimgtest002', 'image/jpeg');
        $this->seedBlob('gpdftest001', 'application/pdf');

        $out = $this->renderList([
            ['id' => 'g1', 'type' => 'gallery', 'data' => [
                'items' => [
                    ['id' => 'gi1', 'type' => 'image', 'data' => ['image' => 'gimgtest001']],
                    ['id' => 'gi2', 'type' => 'image', 'data' => []], // missing uuid
                    ['id' => 'gi3', 'type' => 'image', 'data' => ['image' => 'gimgtest002']],
                    ['id' => 'gi4', 'type' => 'image', 'data' => ['image' => 'gpdftest001']], // non-image
                ],
            ]],
        ]);

        // Resolve-first: exactly 2 anchors survive (missing uuid + PDF omitted).
        self::assertSame(2, substr_count($out, 'class="thallo-block-gallery__item"'));
        // Fallback labels computed over the RESOLVED list, not original positions.
        self::assertStringContainsString('aria-label="Image 1 of 2"', $out);
        self::assertStringContainsString('aria-label="Image 2 of 2"', $out);
        self::assertStringContainsString('data-lightbox="1"', $out);
    }

    public function testGalleryAuthoredAltWinsOverFallbackLabel(): void
    {
        $this->seedBlob('gimgalt0001', 'image/jpeg');
        $out = $this->renderList([
            ['id' => 'g1', 'type' => 'gallery', 'data' => [
                'items' => [
                    ['id' => 'gi1', 'type' => 'image', 'data' => ['image' => 'gimgalt0001', 'alt' => 'Team photo']],
                ],
            ]],
        ]);
        self::assertStringContainsString('aria-label="Team photo"', $out);
        self::assertStringNotContainsString('Image 1 of 1', $out);
    }

    public function testGalleryLightboxDefaultsTrueButAuthoredFalseSurvives(): void
    {
        $this->seedBlob('gimglb00001', 'image/jpeg');
        $withDefault = $this->renderList([
            ['id' => 'g1', 'type' => 'gallery', 'data' => [
                'items' => [['id' => 'gi1', 'type' => 'image', 'data' => ['image' => 'gimglb00001']]],
            ]],
        ]);
        self::assertStringContainsString('data-lightbox="1"', $withDefault);

        // The `?? true` pin: an authored false must survive (never |default(true),
        // which would treat false as "unset" and re-coerce it to true).
        $withFalse = $this->renderList([
            ['id' => 'g2', 'type' => 'gallery', 'data' => [
                'items' => [['id' => 'gi1', 'type' => 'image', 'data' => ['image' => 'gimglb00001']]],
                'lightbox' => false,
            ]],
        ]);
        self::assertStringContainsString('data-lightbox="0"', $withFalse);
    }

    public function testGalleryColumnsAndAspectModifiersOnRoot(): void
    {
        $out = $this->renderList([
            ['id' => 'g1', 'type' => 'gallery', 'data' => ['items' => [], 'columns' => '4', 'aspect' => 'square']],
        ]);
        self::assertStringContainsString('thallo-block-gallery--cols-4', $out);
        self::assertStringContainsString('thallo-block-gallery--square', $out);
    }

    // ---- hero heading_level / carousel hero style (modern-blocks spec §2/§4) -----

    public function testHeroDefaultHeadingLevelRendersH1(): void
    {
        $out = $this->renderList([['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'Big']]]);
        self::assertStringContainsString('<h1 class="thallo-block-hero__title">Big</h1>', $out);
    }

    public function testHeroHeadingLevelH2RendersH2(): void
    {
        $out = $this->renderList([
            ['id' => 'h1', 'type' => 'hero', 'data' => ['title' => 'Big', 'heading_level' => 'h2']],
        ]);
        self::assertStringContainsString('<h2 class="thallo-block-hero__title">Big</h2>', $out);
    }

    public function testCarouselHeroStyleAddsModifierClass(): void
    {
        $hero = $this->renderList([
            ['id' => 'c1', 'type' => 'carousel', 'data' => ['style' => 'hero', 'slides' => []]],
        ]);
        self::assertStringContainsString('thallo-block-carousel--hero', $hero);

        $default = $this->renderList([
            ['id' => 'c2', 'type' => 'carousel', 'data' => ['slides' => []]],
        ]);
        self::assertStringNotContainsString('thallo-block-carousel--hero', $default);
    }

    /**
     * Transition + height configs (slider-config ruling, 2026-08-02). Both are
     * closed enums with template-side fallbacks: absent or unknown stored values
     * render the defaults (slide / standard) — never an arbitrary class.
     */
    public function testCarouselTransitionAndHeightModifiers(): void
    {
        $fade = $this->renderList([
            ['id' => 'c1', 'type' => 'carousel', 'data' => ['transition' => 'fade', 'slides' => []]],
        ]);
        self::assertStringContainsString('thallo-block-carousel--fade', $fade);
        self::assertStringContainsString('data-transition="fade"', $fade);

        $zoom = $this->renderList([
            ['id' => 'c2', 'type' => 'carousel', 'data' => ['transition' => 'zoom', 'slides' => []]],
        ]);
        self::assertStringContainsString('thallo-block-carousel--zoom', $zoom);
        self::assertStringContainsString('data-transition="zoom"', $zoom);

        // Absent AND unknown transitions both fall back to slide: no modifier.
        foreach ([[], ['transition' => 'sparkle']] as $data) {
            $out = $this->renderList([
                ['id' => 'c3', 'type' => 'carousel', 'data' => $data + ['slides' => []]],
            ]);
            self::assertStringContainsString('data-transition="slide"', $out);
            self::assertStringNotContainsString('thallo-block-carousel--fade', $out);
            self::assertStringNotContainsString('thallo-block-carousel--zoom', $out);
        }

        $tall = $this->renderList([
            ['id' => 'c4', 'type' => 'carousel', 'data' => ['height' => 'tall', 'slides' => []]],
        ]);
        self::assertStringContainsString('thallo-block-carousel--h-tall', $tall);

        // Absent AND unknown heights both fall back to the standard preset.
        foreach ([[], ['height' => 'gigantic']] as $data) {
            $out = $this->renderList([
                ['id' => 'c5', 'type' => 'carousel', 'data' => $data + ['slides' => []]],
            ]);
            self::assertStringContainsString('thallo-block-carousel--h-standard', $out);
        }
    }

    /**
     * Animated-text loop + break tokens (2026-08 follow-up). data-loop mirrors
     * the boolean; the literal tokens <br>, <br/>, <br /> typed into prefix,
     * suffix, OR a rotate word become real line breaks — everything else stays
     * escaped (the tokens are recognized AFTER escaping; author markup never
     * goes live). The visually-hidden stable phrase strips the tokens: AT hears
     * the phrase, never "less-than br".
     */
    public function testAnimatedTextLoopAttributeAndBreakTokens(): void
    {
        $on = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text',
                'data' => ['loop' => true, 'prefix' => 'Hi', 'rotate_words' => "A\nB"]],
        ]);
        self::assertStringContainsString('data-loop="1"', $on);

        $off = $this->renderList([
            ['id' => 'a2', 'type' => 'animated_text', 'data' => ['prefix' => 'Hi', 'rotate_words' => "A\nB"]],
        ]);
        self::assertStringContainsString('data-loop="0"', $off);

        $out = $this->renderList([
            ['id' => 'a3', 'type' => 'animated_text', 'data' => [
                'prefix' => 'Build<br/>fast',
                'rotate_words' => "multi<br/>line\nplain",
                'suffix' => 'now<br>go',
            ]],
        ]);
        self::assertStringContainsString('Build<br>fast', $out);
        self::assertStringContainsString('multi<br>line', $out);
        self::assertStringContainsString('now<br>go', $out);
        // The stable SR phrase joins with plain spaces — no tokens, no breaks.
        self::assertStringContainsString(
            '<span class="thallo-block-animated_text__sr">Build fast multi line now go</span>',
            $out,
        );

        // The tokens are the ONLY markup that comes alive: everything else escapes.
        $xss = $this->renderList([
            ['id' => 'a4', 'type' => 'animated_text', 'data' => [
                'prefix' => '<script>alert(1)</script>x<br/>y',
                'rotate_words' => "<b>bold</b>\nplain",
            ]],
        ]);
        self::assertStringContainsString('&lt;script&gt;', $xss);
        self::assertStringNotContainsString('<script>', $xss);
        self::assertStringContainsString('x<br>y', $xss);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $xss);
    }

    /**
     * Animated-text rotation interval + per-segment styling (2026-08 follow-up).
     * interval: seconds each word stays (numeric_clamp 0.5–10; absent/garbage
     * emits nothing — the runtime's 2.5s default applies). Segments: prefix,
     * rotating stack, and suffix each take independent color (hex_color-guarded
     * inline style), relative size class, and bold/italic classes.
     */
    public function testAnimatedTextIntervalAndSegmentStyles(): void
    {
        $timed = $this->renderList([
            ['id' => 'a1', 'type' => 'animated_text',
                'data' => ['interval' => 4, 'prefix' => 'Hi', 'rotate_words' => "A\nB"]],
        ]);
        self::assertStringContainsString('data-interval="4"', $timed);

        $clamped = $this->renderList([
            ['id' => 'a2', 'type' => 'animated_text',
                'data' => ['interval' => 99, 'prefix' => 'Hi', 'rotate_words' => "A\nB"]],
        ]);
        self::assertStringContainsString('data-interval="10"', $clamped);

        foreach ([[], ['interval' => 'warp']] as $data) {
            $out = $this->renderList([
                ['id' => 'a3', 'type' => 'animated_text',
                    'data' => $data + ['prefix' => 'Hi', 'rotate_words' => "A\nB"]],
            ]);
            self::assertStringNotContainsString('data-interval', $out);
        }

        $styled = $this->renderList([
            ['id' => 'a4', 'type' => 'animated_text', 'data' => [
                'prefix' => 'Craft', 'rotate_words' => "bold\nthings", 'suffix' => 'daily',
                'prefix_color' => '#ff0000', 'prefix_size' => 'sm', 'prefix_italic' => true,
                'rotate_color' => '#00ff00', 'rotate_size' => 'xl', 'rotate_bold' => true,
                'suffix_size' => 'lg', 'suffix_bold' => true, 'suffix_italic' => true,
            ]],
        ]);
        // Prefix span: small + italic + red.
        self::assertMatchesRegularExpression(
            '#<span class="thallo-block-animated_text__prefix thallo-block-animated_text__seg--sm '
            . 'thallo-block-animated_text__seg--italic" style="color: \#ff0000">Craft</span>#',
            $styled,
        );
        // Rotating stack: xl + bold + green on the __rotate span itself.
        self::assertMatchesRegularExpression(
            '#<span class="thallo-block-animated_text__rotate thallo-block-animated_text__seg--xl '
            . 'thallo-block-animated_text__seg--bold" style="color: \#00ff00">#',
            $styled,
        );
        // Suffix span: lg + bold + italic, no color -> no style attribute.
        self::assertMatchesRegularExpression(
            '#<span class="thallo-block-animated_text__suffix thallo-block-animated_text__seg--lg '
            . 'thallo-block-animated_text__seg--bold thallo-block-animated_text__seg--italic">daily</span>#',
            $styled,
        );

        // A non-hex color value is DROPPED, never emitted into the style attr.
        $bad = $this->renderList([
            ['id' => 'a5', 'type' => 'animated_text', 'data' => [
                'prefix' => 'Hi', 'rotate_words' => "A\nB",
                'prefix_color' => 'red;background:url(x)',
            ]],
        ]);
        self::assertStringNotContainsString('background:url', $bad);
        self::assertStringNotContainsString('style="color: red', $bad);
    }

    /**
     * Configurable transition duration (slider-config follow-up): seconds, one
     * value pacing every mode — the runtime reads data-duration for the slide
     * scroll; fade/zoom consume the --carousel-duration custom property. Emitted
     * only for a NUMERIC stored value, clamped to 0.2–5; absent/garbage values
     * emit neither (the theme defaults apply).
     */
    public function testCarouselConfigurableTransitionDuration(): void
    {
        $out = $this->renderList([
            ['id' => 'c1', 'type' => 'carousel', 'data' => ['transition_duration' => 2.5, 'slides' => []]],
        ]);
        self::assertStringContainsString('data-duration="2.5"', $out);
        self::assertStringContainsString('style="--carousel-duration: 2.5s"', $out);

        // Clamped to the 0.2–5 window on both ends.
        $high = $this->renderList([
            ['id' => 'c2', 'type' => 'carousel', 'data' => ['transition_duration' => 99, 'slides' => []]],
        ]);
        self::assertStringContainsString('data-duration="5"', $high);
        $low = $this->renderList([
            ['id' => 'c3', 'type' => 'carousel', 'data' => ['transition_duration' => 0.01, 'slides' => []]],
        ]);
        self::assertStringContainsString('data-duration="0.2"', $low);

        // Absent and non-numeric: no attribute, no style — theme defaults apply.
        foreach ([[], ['transition_duration' => 'fast']] as $data) {
            $out = $this->renderList([
                ['id' => 'c4', 'type' => 'carousel', 'data' => $data + ['slides' => []]],
            ]);
            self::assertStringNotContainsString('data-duration', $out);
            self::assertStringNotContainsString('--carousel-duration', $out);
        }
    }

    /**
     * Canvas empty-state (blog_posts precedent): an empty carousel/gallery renders
     * as literally nothing on the live page — correct there, but invisible in the
     * canvas editor, so authors can't see the block exists or that its region is
     * empty. Preview annotation mode shows a placeholder; live mode never does.
     */
    public function testEmptyCarouselAndGalleryShowPlaceholderOnlyInPreview(): void
    {
        $ext = $this->container()->get(RenderContextExtension::class);

        // Live render: no placeholder, roots still emitted.
        $live = $this->renderList([
            ['id' => 'ce1', 'type' => 'carousel', 'data' => ['style' => 'hero', 'slides' => []]],
            ['id' => 'ge1', 'type' => 'gallery', 'data' => ['items' => []]],
        ]);
        self::assertStringNotContainsString('thallo-block-carousel__empty', $live);
        self::assertStringNotContainsString('thallo-block-gallery__empty', $live);

        // Preview annotation: placeholders visible.
        $ext->setBlockAnnotations(true);
        try {
            $preview = $this->renderList([
                ['id' => 'ce2', 'type' => 'carousel', 'data' => ['style' => 'hero', 'slides' => []]],
                ['id' => 'ge2', 'type' => 'gallery', 'data' => ['items' => []]],
            ]);
        } finally {
            $ext->setBlockAnnotations(false);
        }
        self::assertStringContainsString('thallo-block-carousel__empty', $preview);
        self::assertStringContainsString('Empty carousel', $preview);
        self::assertStringContainsString('thallo-block-gallery__empty', $preview);
        self::assertStringContainsString('Empty gallery', $preview);

        // A populated carousel never shows the placeholder, even in preview.
        $ext->setBlockAnnotations(true);
        try {
            $populated = $this->renderList([
                ['id' => 'ce3', 'type' => 'carousel', 'data' => ['slides' => [
                    ['id' => 'ce3s1', 'type' => 'rich_text', 'data' => ['content' => '<p>Slide</p>']],
                ]]],
            ]);
        } finally {
            $ext->setBlockAnnotations(false);
        }
        self::assertStringNotContainsString('thallo-block-carousel__empty', $populated);
    }
}
