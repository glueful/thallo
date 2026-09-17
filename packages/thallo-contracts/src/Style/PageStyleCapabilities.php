<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * What a page's own style frame (`_presentation.style`, the Page tab's Styles) may declare:
 * spacing and a background. One list for the validator (core) and the emitter (render).
 */
final class PageStyleCapabilities
{
    /** @var list<string> */
    public const PATHS = ['spacing', 'colors.surface'];

    public static function capabilities(): StyleCapabilities
    {
        return StyleCapabilities::fromDeclaration(self::PATHS);
    }
}
