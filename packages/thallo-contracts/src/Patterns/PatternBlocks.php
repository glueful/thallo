<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * The page library's construction toolkit: the blocks and style declarations the shipped sections
 * are made of, shared with packs so a pack's sections are built the way core's are. The shapes are
 * the structure picker's presets — a section band, a stack, a grid, a section header — and every
 * value is a theme token or a choice, never a raw length or colour, so a section follows the theme.
 *
 * Blocks carry no ids: the editor mints them, as it does for every block it creates.
 */
final class PatternBlocks
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    public static function block(string $type, array $data, array $style = []): array
    {
        return ['type' => $type, 'data' => $data, 'settings' => $style === [] ? [] : ['style' => $style]];
    }

    /** @return array{type:string,value:string} */
    public static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    /** @return array{type:string,value:string} */
    public static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
    }

    /**
     * A section band, as the structure picker's Section preset builds it: vertical padding, the
     * container width, a flex column with a gap.
     *
     * @param list<array<string,mixed>> $content
     * @param array<string,mixed> $layout layout declarations over the band's own
     * @return array<string,mixed>
     */
    public static function band(array $content, array $layout = [], ?string $surface = null): array
    {
        $style = [
            'spacing' => ['padding' => [
                'top' => ['base' => self::token('spacing.3xl')],
                'bottom' => ['base' => self::token('spacing.3xl')],
            ]],
            'layout' => array_replace([
                'content_width' => ['base' => self::token('width.container')],
                'display' => ['base' => self::choice('flex')],
                'direction' => ['base' => self::choice('column')],
                'gap' => ['row' => ['base' => self::token('spacing.xl')]],
            ], $layout),
        ];
        if ($surface !== null) {
            $style['colors'] = ['surface' => self::token($surface)];
        }
        return self::block('container', ['element' => 'section', 'content' => $content], $style);
    }

    /** Two tracks from `lg` up, a column below it (the Section split preset's arrangement). */
    /** @return array<string,mixed> */
    public static function splitAtLg(): array
    {
        return [
            'display' => ['base' => self::choice('flex'), 'lg' => self::choice('grid')],
            'columns' => ['lg' => self::choice('2')],
            'align_items' => ['lg' => self::choice('center')],
            'gap' => [
                'row' => ['base' => self::token('spacing.xl')],
                'column' => ['lg' => self::token('spacing.2xl')],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    public static function stack(array $content, ?string $align = null): array
    {
        $layout = [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('column')],
            'gap' => ['row' => ['base' => self::token('spacing.md')]],
        ];
        if ($align !== null) {
            $layout['align_items'] = ['base' => self::choice($align)];
        }
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => $layout]);
    }

    /**
     * One column on a phone, `$md` from md, `$columns` from lg.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    public static function grid(string $columns, array $content, string $md = '2'): array
    {
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => self::choice('grid')],
            'columns' => ['base' => self::choice('1'), 'md' => self::choice($md), 'lg' => self::choice($columns)],
            'gap' => [
                'row' => ['base' => self::token('spacing.lg')],
                'column' => ['base' => self::token('spacing.lg')],
            ],
        ]]);
    }

    /** The section header group: an eyebrow, a heading and an optional lead, centred. */
    /** @return array<string,mixed> */
    public static function header(string $eyebrow, string $title, ?string $lead): array
    {
        $content = [
            self::block('rich_text', ['body' => '<p>' . $eyebrow . '</p>'], [
                'colors' => ['text' => self::token('color.accent')],
                'typography' => ['weight' => ['base' => self::choice('semibold')]],
                'alignment' => ['text' => ['base' => self::choice('center')]],
            ]),
            self::heading($title, 'h2', 'center'),
        ];
        if ($lead !== null) {
            $content[] = self::block('rich_text', ['body' => '<p>' . $lead . '</p>'], [
                'colors' => ['text' => self::token('color.muted')],
                'typography' => ['size' => ['base' => self::token('typography.size.lg')]],
                'width' => ['base' => self::token('width.content')],
                'alignment' => [
                    'text' => ['base' => self::choice('center')],
                    'self' => ['base' => self::choice('center')],
                ],
            ]);
        }
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('column')],
            'gap' => ['row' => ['base' => self::token('spacing.sm')]],
        ]]);
    }

    /** @return array<string,mixed> */
    public static function heading(string $text, string $level, string $align): array
    {
        return self::block('heading', ['text' => $text, 'level' => $level], [
            'alignment' => ['text' => ['base' => self::choice($align)]],
        ]);
    }

    /** @return array<string,mixed> */
    public static function text(string $html, string $align, ?string $color = null): array
    {
        $style = ['alignment' => ['text' => ['base' => self::choice($align)]]];
        if ($color !== null) {
            $style['colors'] = ['text' => self::token($color)];
        }
        return self::block('rich_text', ['body' => $html], $style);
    }

    /** @return array<string,mixed> */
    public static function button(string $label, string $variant): array
    {
        return self::block(
            'button',
            ['label' => $label, 'url' => '#', 'variant' => $variant, 'color' => 'primary', 'size' => 'lg'],
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $buttons the first is solid, the rest outlined
     * @return array<string,mixed>
     */
    public static function hero(array $data, array $buttons): array
    {
        $links = [];
        foreach ($buttons as $i => $label) {
            $links[] = self::button($label, $i === 0 ? 'solid' : 'outline');
        }
        return self::block('hero', $data + ['links' => $links]);
    }

    /** @return array<string,mixed> */
    public static function feature(
        string $icon,
        string $title,
        string $description,
        string $variant = 'outline',
        string $orientation = 'vertical',
    ): array {
        return self::block('feature', compact('icon', 'title', 'description', 'variant', 'orientation'));
    }

    /** @return array<string,mixed> */
    public static function step(string $number, string $title, string $description): array
    {
        return self::block('feature', [
            'title' => $title,
            'description' => $description,
            'marker' => 'number',
            'number' => $number,
            'marker_background' => 'accent',
            'marker_color' => 'accent-contrast',
            'variant' => 'plain',
            'orientation' => 'vertical',
        ]);
    }

    /** @return array<string,mixed> */
    public static function stat(string $figure, string $label): array
    {
        return self::stack([
            self::block('heading', ['text' => $figure, 'level' => 'h3'], [
                'alignment' => ['text' => ['base' => self::choice('center')]],
                'typography' => ['size' => ['base' => self::token('typography.size.2xl')]],
                'colors' => ['text' => self::token('color.accent')],
            ]),
            self::text('<p>' . $label . '</p>', 'center', 'color.muted'),
        ]);
    }

    /** @return array<string,mixed> */
    public static function quote(string $quote, string $name, string $role): array
    {
        return self::block('card', [
            'variant' => 'soft',
            'orientation' => 'vertical',
            'description' => $quote,
            'body' => [
                self::block('rich_text', ['body' => '<p><strong>' . $name . '</strong><br />' . $role . '</p>']),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public static function card(string $icon, string $title, string $description): array
    {
        return self::block('card', [
            'icon' => $icon,
            'title' => $title,
            'description' => $description,
            'variant' => 'outline',
            'orientation' => 'horizontal',
            'body' => [],
        ]);
    }

    /** @return array<string,mixed> */
    public static function plan(
        string $title,
        string $description,
        string $price,
        string $features,
        string $button,
        bool $highlight,
    ): array {
        return self::block('pricing_plan', [
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'billing_cycle' => '/month',
            'features' => $features,
            'button_label' => $button,
            'button_url' => '#',
            'button_variant' => $highlight ? 'solid' : 'outline',
            'variant' => 'outline',
            'highlight' => $highlight,
            'orientation' => 'vertical',
        ] + ($highlight ? ['badge' => 'Most popular'] : []));
    }

    /** @return array<string,mixed> */
    public static function question(string $question, string $answer): array
    {
        return self::block('accordion_item', ['question' => $question, 'answer' => '<p>' . $answer . '</p>']);
    }

    /**
     * @param list<string> $buttons
     * @return array<string,mixed>
     */
    public static function cta(
        string $title,
        string $description,
        string $variant,
        string $orientation,
        array $buttons,
    ): array {
        $links = [];
        foreach ($buttons as $i => $label) {
            // On a filled band the first button inverts to stay visible; the theme handles it.
            $links[] = self::button($label, $i === 0 ? 'solid' : 'outline');
        }
        return self::block('cta', compact('title', 'description', 'variant', 'orientation', 'links'));
    }
}
