<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The platform vocabulary (spec §2.1): Thallo owns the names, themes own the values. Scales are
 * ordinal only. A document may reference baseline names only, so no theme can lack one.
 */
final class Vocabulary
{
    public const VERSION = 1;

    private const DOMAINS = [
        'spacing' => ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'],
        'width' => ['narrow', 'content', 'container', 'full'],
        'radius' => ['none', 'sm', 'md', 'lg', 'full'],
        'color' => [
            'background', 'surface', 'surface-2', 'text', 'muted', 'line', 'accent', 'accent-contrast', 'transparent',
            'white', 'black',
            'brand-1', 'brand-1-contrast', 'brand-2', 'brand-2-contrast', 'brand-3', 'brand-3-contrast',
        ],
        'shadow' => ['none', 'xs', 'sm', 'md', 'lg', 'xl'],
        'typography.size' => ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'],
    ];

    /**
     * Tokens with a value that is the same in every theme and scheme, filled in when a theme's
     * manifest omits them — so a token added to the baseline after a theme was copied still
     * resolves. A theme may still map them.
     */
    public const LITERAL_DEFAULTS = ['color.white' => '#ffffff', 'color.black' => '#000000'];

    /**
     * Values the SITE sets, not the theme (custom palette spec §3.1): always the variables
     * themeColorsStyle() emits from the palette. A theme's mapping for these is ignored, so a
     * theme can never bypass the site's hex, its swatches or its contrast checks.
     */
    public const SITE_CONTROLLED = [
        'color.brand-1' => 'var(--brand-1)', 'color.brand-1-contrast' => 'var(--brand-1-ink)',
        'color.brand-2' => 'var(--brand-2)', 'color.brand-2-contrast' => 'var(--brand-2-ink)',
        'color.brand-3' => 'var(--brand-3)', 'color.brand-3-contrast' => 'var(--brand-3-ink)',
    ];

    /** @return list<string> the domains in contract order */
    public static function domains(): array
    {
        return array_keys(self::DOMAINS);
    }

    /** @return list<string> the names of one domain, ordinal order */
    public static function names(string $domain): array
    {
        return self::DOMAINS[$domain] ?? throw new \InvalidArgumentException("unknown vocabulary domain \"{$domain}\"");
    }

    /** @return list<string> every baseline token, `domain.name` */
    public static function all(): array
    {
        $all = [];
        foreach (self::DOMAINS as $domain => $names) {
            foreach ($names as $name) {
                $all[] = "{$domain}.{$name}";
            }
        }
        return $all;
    }

    public static function isBaseline(string $token): bool
    {
        $domain = self::domain($token);
        if ($domain === null) {
            return false;
        }
        $name = substr($token, strlen($domain) + 1);
        return in_array($name, self::DOMAINS[$domain], true);
    }

    /** The domain of a token (`typography.size` for `typography.size.md`), or null when unknown. */
    public static function domain(string $token): ?string
    {
        foreach (array_keys(self::DOMAINS) as $domain) {
            if (str_starts_with($token, $domain . '.')) {
                return $domain;
            }
        }
        return null;
    }
}
