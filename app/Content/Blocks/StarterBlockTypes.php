<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * The starter block library (starter-library spec §1; expanded + hero/cta
 * reshaped by the block-library spec) — DATA ONLY, the one source of truth for
 * `thallo:blocks:seed`. Every schema passes BlockTypeRepository::create()'s
 * rules (the seeder goes through it, so the starters validate themselves). No
 * `reference` fields: reference_type targets site-specific content types.
 *
 * Conventions carried by the definitions themselves:
 * - `block_types` allowlists are picker-only (never validation).
 * - `Items` category = single-purpose child blocks for collection fields.
 * - `pattern` / `min` / `max` are schema-declared value constraints
 *   (block-library spec §5): container hex colors, shortcode names.
 * - `html` seeds DEACTIVATED (`active` => false): raw output is an explicit
 *   admin opt-in in Settings → Block types.
 */
final class StarterBlockTypes
{
    private const HEX = '#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?';

    /**
     * @return list<array{slug: string, label: string, icon: string, category: string,
     *   description: string, schema: list<array<string,mixed>>, active?: bool}>
     */
    public static function definitions(): array
    {
        return [
            // ---- Layout -----------------------------------------------------
            ['slug' => 'section', 'label' => 'Section', 'icon' => 'i-lucide-rows-3',
                'category' => 'Layout', 'description' => 'A titled band of content with a background style.',
                'schema' => [
                    ['name' => 'headline', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'background', 'type' => 'enum', 'enum' => ['none', 'muted', 'subtle', 'inverted']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'container', 'label' => 'Container', 'icon' => 'i-lucide-square-dashed',
                'category' => 'Layout',
                'description' => 'Free-form styled wrapper: background color/image, overlay, width and padding.',
                'schema' => [
                    ['name' => 'background_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'background_image', 'type' => 'asset'],
                    ['name' => 'bg_size', 'type' => 'enum', 'enum' => ['cover', 'contain', 'auto']],
                    ['name' => 'bg_repeat', 'type' => 'enum', 'enum' => ['no-repeat', 'repeat']],
                    ['name' => 'bg_position', 'type' => 'enum',
                        'enum' => ['center', 'top', 'bottom', 'left', 'right']],
                    ['name' => 'overlay_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'overlay_opacity', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['full', 'contained', 'narrow']],
                    ['name' => 'padding', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
                    ['name' => 'min_height', 'type' => 'enum', 'enum' => ['auto', 'half', 'screen']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'grid', 'label' => 'Grid', 'icon' => 'i-lucide-layout-grid',
                'category' => 'Layout',
                'description' => 'A responsive wrapping grid (or masonry flow) of blocks.',
                'schema' => [
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['1', '2', '3', '4']],
                    ['name' => 'flow', 'type' => 'enum', 'enum' => ['grid', 'masonry']],
                    ['name' => 'gap', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'items', 'type' => 'blocks'],
                ]],
            ['slug' => 'columns', 'label' => 'Columns', 'icon' => 'i-lucide-columns-3',
                'category' => 'Layout', 'description' => 'Two or three columns of blocks.',
                'schema' => [
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['2', '3']],
                    // Ratio presets (columns-sizing spec): one flat enum for both
                    // layouts; a preset that doesn't match `layout` renders as
                    // equal columns (template allowlist guard), never an error.
                    ['name' => 'widths', 'type' => 'enum', 'enum' => [
                        '50-50', '33-67', '67-33', '25-75', '75-25',
                        '33-33-33', '25-50-25', '50-25-25', '25-25-50',
                    ]],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['stretch', 'top', 'center', 'bottom']],
                    ['name' => 'col_1', 'type' => 'blocks'],
                    ['name' => 'col_2', 'type' => 'blocks'],
                    ['name' => 'col_3', 'type' => 'blocks'],
                ]],
            ['slug' => 'navigation', 'label' => 'Navigation', 'icon' => 'i-lucide-menu',
                'category' => 'Layout',
                'description' => 'Links from a navigation menu (structured source — pick a menu, not links).',
                'schema' => [
                    ['name' => 'menu', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                    // RTL-safe alignment (logical): end = right in LTR, left in RTL.
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['sm', 'md', 'lg']],
                    // Style model (navigationMenu-derived, translated to our tokens):
                    // variant picks the shape (filled pill vs plain link), color the
                    // accent (brand vs monochrome), highlight the active indicator.
                    // Replaces the old hover_style/active_style pair.
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['pill', 'link']],
                    ['name' => 'color', 'type' => 'enum', 'enum' => ['primary', 'neutral']],
                    ['name' => 'highlight', 'type' => 'enum', 'enum' => ['none', 'underline', 'bar']],
                    // Submenu presentation: dropdown = one flat panel (children +
                    // grandchildren flattened); columns = megamenu grid (each child
                    // menu item becomes a column of its own children).
                    ['name' => 'submenu_layout', 'type' => 'enum', 'enum' => ['dropdown', 'columns']],
                    ['name' => 'submenu_icon', 'type' => 'enum',
                        'enum' => ['chevron-down', 'chevron-right', 'plus', 'none']],
                    ['name' => 'submenu_trigger', 'type' => 'enum', 'enum' => ['hover', 'click']],
                ]],
            ['slug' => 'separator', 'label' => 'Separator', 'icon' => 'i-lucide-separator-horizontal',
                'category' => 'Layout',
                'description' => 'A horizontal rule, optionally with a centered label and icon.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'type', 'type' => 'enum', 'enum' => ['solid', 'dashed', 'dotted']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                ]],
            ['slug' => 'footer_columns', 'label' => 'Footer columns', 'icon' => 'i-lucide-panel-bottom',
                'category' => 'Layout', 'description' => 'Columns of titled link lists for a site footer.',
                'schema' => [
                    ['name' => 'columns', 'type' => 'json'],
                ]],
            // Nuxt UI Footer shape (refs.md `footer`): a <footer> bar with an optional
            // top band, then left (copyright) / center (links) / right (social) slots.
            // `copyright` is a block list so it can hold a shortcode (e.g. a dynamic
            // copyright/year), rich_text, or a logo — not just static text.
            ['slug' => 'footer', 'label' => 'Footer', 'icon' => 'i-lucide-panels-top-left',
                'category' => 'Layout',
                'description' => 'A footer bar: copyright, links and social, over an optional top band.',
                'schema' => [
                    ['name' => 'top', 'type' => 'blocks'],
                    ['name' => 'copyright', 'type' => 'blocks'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['links', 'navigation']],
                    ['name' => 'social', 'type' => 'blocks', 'block_types' => ['social_links']],
                ]],
            ['slug' => 'spacer', 'label' => 'Spacer', 'icon' => 'i-lucide-move-vertical',
                'category' => 'Layout', 'description' => 'Vertical breathing room.',
                'schema' => [
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                ]],

            // ---- Content ----------------------------------------------------
            // PageHero shape (block-library spec §2b): eyebrow/title/description,
            // button links, media column, vertical (centered) | horizontal.
            ['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-sparkles',
                'category' => 'Content', 'description' => 'Big heading, supporting copy, buttons and media.',
                'schema' => [
                    ['name' => 'headline', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                    ['name' => 'image', 'type' => 'asset'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                ]],
            ['slug' => 'rich_text', 'label' => 'Rich text', 'icon' => 'i-lucide-text',
                'category' => 'Content', 'description' => 'Free-form formatted text.',
                'schema' => [
                    ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ]],
            ['slug' => 'card', 'label' => 'Card', 'icon' => 'i-lucide-rectangle-horizontal',
                'category' => 'Content',
                'description' => 'A content card: icon, title, description and nested blocks.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['outline', 'solid', 'soft', 'subtle', 'ghost', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'body', 'type' => 'blocks'],
                ]],
            ['slug' => 'accordion', 'label' => 'Accordion', 'icon' => 'i-lucide-list-collapse',
                'category' => 'Content', 'description' => 'A stack of expandable question/answer items.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'multiple', 'type' => 'boolean'],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['accordion_item']],
                ]],
            ['slug' => 'collapsible', 'label' => 'Collapsible', 'icon' => 'i-lucide-chevrons-up-down',
                'category' => 'Content',
                'description' => 'A single show/hide disclosure wrapping nested blocks.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'open', 'type' => 'boolean'],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'links', 'label' => 'Links', 'icon' => 'i-lucide-list',
                'category' => 'Content',
                'description' => 'A vertical list of navigation links with an optional title.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'items', 'type' => 'json'],
                ]],
            // PageCTA shape (block-library spec §2b): title/description, the five
            // band variants, orientation/reverse, button links.
            ['slug' => 'cta', 'label' => 'Call to action', 'icon' => 'i-lucide-megaphone',
                'category' => 'Content', 'description' => 'A call-to-action band with buttons.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['solid', 'outline', 'soft', 'subtle', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                ]],
            ['slug' => 'stepper', 'label' => 'Stepper', 'icon' => 'i-lucide-list-ordered',
                'category' => 'Content', 'description' => 'A numbered sequence of steps, horizontal or vertical.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'color', 'type' => 'enum',
                        'enum' => ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['stepper_item']],
                ]],
            ['slug' => 'tabs', 'label' => 'Tabs', 'icon' => 'i-lucide-panels-top-left',
                'category' => 'Content', 'description' => 'Tabbed panels of blocks.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['tab']],
                ]],
            // Nuxt UI Button shape (refs.md `button`): variant + color (primary|neutral,
            // matching navigation) + size, optional leading/trailing icons, block
            // (full-width) and alignment.
            ['slug' => 'button', 'label' => 'Button', 'icon' => 'i-lucide-mouse-pointer-click',
                'category' => 'Content', 'description' => 'A standalone action button.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string', 'required' => true],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['solid', 'outline', 'soft', 'subtle', 'ghost', 'link']],
                    ['name' => 'color', 'type' => 'enum', 'enum' => ['primary', 'neutral']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'leading_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'trailing_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'block', 'type' => 'boolean'],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['left', 'center', 'right']],
                ]],
            // Color-mode switch (color-mode spec §3.5): a 3-way light/system/dark
            // segmented control. Presentation only — no data fields.
            ['slug' => 'color_mode', 'label' => 'Color mode', 'icon' => 'i-lucide-sun-moon',
                'category' => 'Content',
                'description' => 'A light / system / dark color-mode switch for visitors.',
                'schema' => []],
            ['slug' => 'carousel', 'label' => 'Carousel', 'icon' => 'i-lucide-gallery-horizontal',
                'category' => 'Content', 'description' => 'A swipeable slider — each child block is a slide.',
                'schema' => [
                    ['name' => 'slides', 'type' => 'blocks'],
                    ['name' => 'slides_per_view', 'type' => 'enum', 'enum' => ['1', '2', '3']],
                    ['name' => 'arrows', 'type' => 'boolean'],
                    ['name' => 'dots', 'type' => 'boolean'],
                    ['name' => 'autoplay', 'type' => 'boolean'],
                ]],

            // ---- Media ------------------------------------------------------
            ['slug' => 'image', 'label' => 'Image', 'icon' => 'i-lucide-image',
                'category' => 'Media', 'description' => 'A single image with caption.',
                'schema' => [
                    ['name' => 'image', 'type' => 'asset', 'required' => true],
                    ['name' => 'alt', 'type' => 'string'],
                    ['name' => 'caption', 'type' => 'string'],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                ]],
            ['slug' => 'logo', 'label' => 'Logo', 'icon' => 'i-lucide-badge-check',
                'category' => 'Media',
                'description' => 'The site logo (Settings → General); falls back to the site name.',
                'schema' => [
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'link_home', 'type' => 'boolean'],
                ]],
            ['slug' => 'icon', 'label' => 'Icon', 'icon' => 'i-lucide-shapes',
                'category' => 'Media',
                'description' => 'A single decorative icon from the Lucide set, optionally linked.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'url', 'type' => 'string'],
                    ['name' => 'label', 'type' => 'string'],
                ]],
            ['slug' => 'social_links', 'label' => 'Social links', 'icon' => 'i-lucide-share-2',
                'category' => 'Content',
                'description' => 'A row of brand icons linking to social profiles.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['social_link']],
                ]],
            ['slug' => 'logos', 'label' => 'Logos', 'icon' => 'i-lucide-building-2',
                'category' => 'Media', 'description' => 'A “trusted by” strip of brand logos.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'images', 'type' => 'asset', 'multiple' => true],
                    ['name' => 'grayscale', 'type' => 'boolean'],
                    ['name' => 'scroll', 'type' => 'boolean'],
                ]],
            ['slug' => 'video', 'label' => 'Video', 'icon' => 'i-lucide-video',
                'category' => 'Media',
                'description' => 'An uploaded video or a YouTube/Vimeo embed.',
                'schema' => [
                    ['name' => 'source', 'type' => 'enum', 'enum' => ['upload', 'embed']],
                    ['name' => 'video', 'type' => 'asset'],
                    ['name' => 'url', 'type' => 'string'],
                    ['name' => 'poster', 'type' => 'asset'],
                    ['name' => 'caption', 'type' => 'string'],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                ]],
            ['slug' => 'audio', 'label' => 'Audio', 'icon' => 'i-lucide-audio-lines',
                'category' => 'Media', 'description' => 'An uploaded audio file with native controls.',
                'schema' => [
                    ['name' => 'audio', 'type' => 'asset', 'required' => true],
                    ['name' => 'title', 'type' => 'string'],
                ]],

            // ---- Advanced ---------------------------------------------------
            ['slug' => 'html', 'label' => 'HTML', 'icon' => 'i-lucide-code',
                'category' => 'Advanced',
                'description' => 'Raw HTML, rendered verbatim. Trusted editors only — activate to opt in.',
                'active' => false,
                'schema' => [
                    ['name' => 'code', 'type' => 'text'],
                ]],
            ['slug' => 'shortcode', 'label' => 'Shortcode', 'icon' => 'i-lucide-braces',
                'category' => 'Advanced',
                'description' => 'Renders shortcodes/{name}.twig from the theme (or a DB template).',
                'schema' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z][a-z0-9_-]*'],
                    ['name' => 'params', 'type' => 'json'],
                ]],

            // ---- Items (children of collection blocks) ----------------------
            ['slug' => 'feature', 'label' => 'Feature', 'icon' => 'i-lucide-check',
                'category' => 'Items', 'description' => 'One feature: icon, title, description, link.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'url', 'type' => 'string'],
                ]],
            ['slug' => 'accordion_item', 'label' => 'Accordion item', 'icon' => 'i-lucide-chevron-down',
                'category' => 'Items', 'description' => 'One question with a rich-text answer.',
                'schema' => [
                    ['name' => 'question', 'type' => 'string', 'required' => true],
                    ['name' => 'answer', 'type' => 'text', 'format' => 'rich'],
                ]],
            ['slug' => 'tab', 'label' => 'Tab', 'icon' => 'i-lucide-panel-top',
                'category' => 'Items', 'description' => 'One tab: label and panel blocks.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string', 'required' => true],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'stepper_item', 'label' => 'Stepper item', 'icon' => 'i-lucide-circle-dot',
                'category' => 'Items', 'description' => 'One numbered step: title and description.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                ]],
            ['slug' => 'social_link', 'label' => 'Social link', 'icon' => 'i-lucide-link',
                'category' => 'Items', 'description' => 'One social profile: brand icon + URL.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'required' => true,
                        'pattern' => 'brand:[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'brand-icon'],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'label', 'type' => 'string'],
                ]],
        ];
    }
}
