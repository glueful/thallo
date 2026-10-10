<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The platform vocabulary (spec §2.1): Thallo owns the names, themes own the values. Scales are
 * ordinal only. A document may reference baseline names only — brand colours by their family's
 * pattern — so no theme can lack one.
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

    /** A brand colour's name (custom palette spec §3.1): `brand-N` or `brand-N-contrast`, N from 1 to 9999. */
    public const BRAND_COLOR = '/\Abrand-[1-9][0-9]{0,3}(-contrast)?\z/';

    /** Whether a colour name is a brand colour's: a family of names, not a list. */
    public static function isBrandColor(string $name): bool
    {
        return preg_match(self::BRAND_COLOR, $name) === 1;
    }

    /**
     * The value a brand token always has (custom palette spec §3.1): the variables
     * themeColorsStyle() emits from the site's settings. Null for any other token. A theme's
     * mapping for a brand token is ignored, so no theme can bypass the site's hex, its swatches
     * or its contrast checks.
     */
    public static function siteControlled(string $token): ?string
    {
        if (!str_starts_with($token, 'color.') || !self::isBrandColor($name = substr($token, 6))) {
            return null;
        }
        return str_ends_with($name, '-contrast')
            ? 'var(--' . substr($name, 0, -strlen('-contrast')) . '-ink)'
            : "var(--{$name})";
    }

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
        return in_array($name, self::DOMAINS[$domain], true) || ($domain === 'color' && self::isBrandColor($name));
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
