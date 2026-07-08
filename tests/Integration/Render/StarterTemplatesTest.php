<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\StarterBlockTypes;
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
}
