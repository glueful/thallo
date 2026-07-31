<?php

declare(strict_types=1);

namespace App\Content\Regions;

/**
 * Chrome policy as code (global-regions spec §4/§6): which blocks a region may
 * contain and which settings keys it accepts. Deliberately NOT DB state — a
 * product decision versioned with the code. Palettes are SERVER-enforced
 * (RegionValidator), a pinned divergence from the picker-only block_types
 * convention: the "structured region" promise is a hard guarantee.
 */
final class RegionDefinitions
{
    /** @var array<string, list<string>> region slug → allowed TOP-LEVEL block types */
    public const PALETTES = [
        // `mini-cart` and `wishlist-link` are commerce-owned (thallo.commerce): the picker only
        // offers each while its capability is on (it needs the registered block-type definition),
        // and a stored one renders through the missing-template fallback while the capability is
        // off — the palette entries themselves are inert without their owning pack.
        'header' => [
            'logo', 'navigation', 'button', 'color_mode', 'social_links', 'container', 'columns', 'rich_text',
            'mini-cart', 'wishlist-link',
        ],
        'footer' => [
            'logo', 'navigation', 'button', 'social_links', 'container', 'columns', 'rich_text',
            'separator', 'spacer', 'icon', 'image', 'shortcode', 'html',
            'footer', 'links',
            'mini-cart', 'wishlist-link',
        ],
    ];

    /** @var array<string, list<string>> region slug → allowed settings keys */
    public const SETTINGS_KEYS = [
        'header' => ['sticky', 'width'],
        'footer' => ['width'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::PALETTES);
    }
}
