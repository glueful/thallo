<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The named built-in font stacks (block typeface spec §2.1, §3.3): system stacks that download nothing.
 * One source for core (the library's fallbacks), render (the typeface utilities and the Appearance
 * pairings) and the contracts that describe them.
 */
final class FontStacks
{
    public const SERIF = '"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif';
    public const SYSTEM = 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    public const HUMANIST = 'Seravek,"Gill Sans Nova",Ubuntu,Calibri,"DejaVu Sans",source-sans-pro,sans-serif';
    public const GEOMETRIC = 'Avenir,Montserrat,Corbel,"URW Gothic",source-sans-pro,sans-serif';
    public const SLAB = 'Rockwell,"Rockwell Nova","Roboto Slab","DejaVu Serif","Sitka Small",serif';
    public const MONO = 'ui-monospace,"Cascadia Code","Source Code Pro",Menlo,Consolas,"DejaVu Sans Mono",monospace';
    public const CURSIVE = '"Snell Roundhand","Segoe Script","Brush Script MT",cursive';

    /** The fallbacks an uploaded family may choose, in picker order; `sans-serif` is the default. */
    public const FALLBACKS = ['sans-serif', 'serif', 'monospace', 'cursive', 'system-ui'];

    private const NAMED = [
        'serif' => self::SERIF,
        'humanist' => self::HUMANIST,
        'geometric' => self::GEOMETRIC,
        'slab' => self::SLAB,
        'mono' => self::MONO,
        'system' => self::SYSTEM,
    ];

    /** The stack of one of the six device built-ins (`theme` is the theme's own face, not a stack). */
    public static function named(string $id): ?string
    {
        return self::NAMED[$id] ?? null;
    }

    /** The named stack an uploaded family falls back to, ending with its generic family. */
    public static function forFallback(string $generic): string
    {
        return match ($generic) {
            'serif' => self::SERIF,
            'monospace' => self::MONO,
            'cursive' => self::CURSIVE,
            'system-ui' => self::SYSTEM,
            default => (string) preg_replace('/,[^,]+$/', ',sans-serif', self::SYSTEM),
        };
    }
}
