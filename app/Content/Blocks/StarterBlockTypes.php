<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * The starter block library (starter-library spec §1) — DATA ONLY, the one source of
 * truth for `lemma:blocks:seed`. Every schema passes BlockTypeRepository::create()'s
 * §2 rules (the seeder goes through it, so the starters validate themselves). No
 * `reference` fields: reference_type targets site-specific content types.
 */
final class StarterBlockTypes
{
    /**
     * @return list<array{slug: string, label: string, icon: string, category: string,
     *   description: string, schema: list<array<string,mixed>>}>
     */
    public static function definitions(): array
    {
        return [
            ['slug' => 'section', 'label' => 'Section', 'icon' => 'i-lucide-rows-3',
                'category' => 'Layout', 'description' => 'A titled band of content with a background style.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'background', 'type' => 'enum', 'enum' => ['none', 'subtle', 'emphasis']],
                    ['name' => 'content', 'type' => 'blocks'],
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
            ['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-sparkles',
                'category' => 'Content', 'description' => 'Big heading, optional image and call to action.',
                'schema' => [
                    ['name' => 'heading', 'type' => 'string', 'required' => true],
                    ['name' => 'subheading', 'type' => 'string'],
                    ['name' => 'image', 'type' => 'asset'],
                    ['name' => 'alignment', 'type' => 'enum', 'enum' => ['left', 'center']],
                    ['name' => 'cta_label', 'type' => 'string'],
                    ['name' => 'cta_url', 'type' => 'string'],
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
            ['slug' => 'cta', 'label' => 'Call to action', 'icon' => 'i-lucide-megaphone',
                'category' => 'Content', 'description' => 'Heading, supporting text and a button.',
                'schema' => [
                    ['name' => 'heading', 'type' => 'string', 'required' => true],
                    ['name' => 'body', 'type' => 'text'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['primary', 'secondary']],
                ]],
            ['slug' => 'image', 'label' => 'Image', 'icon' => 'i-lucide-image',
                'category' => 'Media', 'description' => 'A single image with caption.',
                'schema' => [
                    ['name' => 'image', 'type' => 'asset', 'required' => true],
                    ['name' => 'alt', 'type' => 'string'],
                    ['name' => 'caption', 'type' => 'string'],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                ]],
            ['slug' => 'gallery', 'label' => 'Gallery', 'icon' => 'i-lucide-layout-grid',
                'category' => 'Media', 'description' => 'A grid of images.',
                'schema' => [
                    ['name' => 'images', 'type' => 'asset', 'multiple' => true],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                ]],
        ];
    }
}
