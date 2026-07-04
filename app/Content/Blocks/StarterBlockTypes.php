<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * The starter block library (starter-library spec §1; expanded + hero/cta
 * reshaped by the block-library spec) — DATA ONLY, the one source of truth for
 * `lemma:blocks:seed`. Every schema passes BlockTypeRepository::create()'s
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
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'background', 'type' => 'enum', 'enum' => ['none', 'subtle', 'emphasis']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'container', 'label' => 'Container', 'icon' => 'i-lucide-square-dashed',
                'category' => 'Layout',
                'description' => 'Free-form styled wrapper: background color/image, overlay, width and padding.',
                'schema' => [
                    ['name' => 'background_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'background_image', 'type' => 'asset'],
                    ['name' => 'bg_size', 'type' => 'enum', 'enum' => ['cover', 'contain', 'auto']],
                    ['name' => 'bg_repeat', 'type' => 'enum',
                        'enum' => ['no-repeat', 'repeat', 'repeat-x', 'repeat-y']],
                    ['name' => 'bg_position', 'type' => 'enum',
                        'enum' => ['center', 'top', 'bottom', 'left', 'right']],
                    ['name' => 'overlay_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'overlay_opacity', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['full', 'contained', 'narrow']],
                    ['name' => 'padding', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
                    ['name' => 'min_height', 'type' => 'enum', 'enum' => ['auto', 'half', 'full']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'grid', 'label' => 'Grid', 'icon' => 'i-lucide-layout-grid',
                'category' => 'Layout',
                'description' => 'A responsive wrapping grid (or masonry flow) of blocks.',
                'schema' => [
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                    ['name' => 'flow', 'type' => 'enum', 'enum' => ['grid', 'masonry']],
                    ['name' => 'gap', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'items', 'type' => 'blocks'],
                ]],
            ['slug' => 'columns', 'label' => 'Columns', 'icon' => 'i-lucide-columns-3',
                'category' => 'Layout', 'description' => 'Two or three columns of blocks.',
                'schema' => [
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['2', '3']],
                    ['name' => 'col_1', 'type' => 'blocks'],
                    ['name' => 'col_2', 'type' => 'blocks'],
                    ['name' => 'col_3', 'type' => 'blocks'],
                ]],
            ['slug' => 'divider', 'label' => 'Divider', 'icon' => 'i-lucide-minus',
                'category' => 'Layout', 'description' => 'A horizontal rule or visual break.',
                'schema' => [
                    ['name' => 'style', 'type' => 'enum', 'enum' => ['line', 'space']],
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
            ['slug' => 'quote', 'label' => 'Quote', 'icon' => 'i-lucide-quote',
                'category' => 'Content', 'description' => 'A pull quote with attribution.',
                'schema' => [
                    ['name' => 'text', 'type' => 'text', 'required' => true],
                    ['name' => 'attribution', 'type' => 'string'],
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
            ['slug' => 'features', 'label' => 'Features', 'icon' => 'i-lucide-list-checks',
                'category' => 'Content', 'description' => 'A titled grid of feature items.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'intro', 'type' => 'text'],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['feature']],
                ]],
            ['slug' => 'testimonials', 'label' => 'Testimonials', 'icon' => 'i-lucide-message-square-quote',
                'category' => 'Content', 'description' => 'Attributed testimonial cards, grid or single.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['grid', 'single']],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['testimonial']],
                ]],
            ['slug' => 'faq', 'label' => 'FAQ', 'icon' => 'i-lucide-circle-help',
                'category' => 'Content', 'description' => 'An accordion of questions and answers.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'multiple', 'type' => 'boolean'],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['faq_item']],
                ]],
            ['slug' => 'tabs', 'label' => 'Tabs', 'icon' => 'i-lucide-panels-top-left',
                'category' => 'Content', 'description' => 'Tabbed panels of blocks.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['tab']],
                ]],
            ['slug' => 'steps', 'label' => 'Steps', 'icon' => 'i-lucide-list-ordered',
                'category' => 'Content', 'description' => 'A numbered how-it-works sequence.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['step']],
                ]],
            ['slug' => 'button', 'label' => 'Button', 'icon' => 'i-lucide-mouse-pointer-click',
                'category' => 'Content', 'description' => 'A standalone action button.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string', 'required' => true],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['solid', 'outline', 'soft', 'ghost']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['sm', 'md', 'lg']],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['left', 'center', 'right']],
                ]],
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
            ['slug' => 'gallery', 'label' => 'Gallery', 'icon' => 'i-lucide-images',
                'category' => 'Media', 'description' => 'A grid of images.',
                'schema' => [
                    ['name' => 'images', 'type' => 'asset', 'multiple' => true],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
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
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'url', 'type' => 'string'],
                    ['name' => 'label', 'type' => 'string'],
                ]],
            ['slug' => 'logo_cloud', 'label' => 'Logo cloud', 'icon' => 'i-lucide-building-2',
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
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'url', 'type' => 'string'],
                ]],
            ['slug' => 'testimonial', 'label' => 'Testimonial', 'icon' => 'i-lucide-user-round',
                'category' => 'Items', 'description' => 'One testimonial: quote, author, role, avatar.',
                'schema' => [
                    ['name' => 'quote', 'type' => 'text', 'required' => true],
                    ['name' => 'author', 'type' => 'string'],
                    ['name' => 'role', 'type' => 'string'],
                    ['name' => 'avatar', 'type' => 'asset'],
                ]],
            ['slug' => 'faq_item', 'label' => 'FAQ item', 'icon' => 'i-lucide-chevron-down',
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
            ['slug' => 'step', 'label' => 'Step', 'icon' => 'i-lucide-circle-dot',
                'category' => 'Items', 'description' => 'One numbered step: title and description.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                ]],
        ];
    }
}
