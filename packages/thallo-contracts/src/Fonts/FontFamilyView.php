<?php

declare(strict_types=1);

namespace Thallo\Contracts\Fonts;

/** One uploaded family as a snapshot saw it. Only the ID ever reaches CSS; the name is display text. */
final class FontFamilyView
{
    /**
     * @param list<array{blob_uuid: string, url: string, weight_min: int, weight_max: int, italic: bool,
     *     variable: bool, unknown: bool}> $faces upright before italic, then by weight; `url` is '' when
     *     the file is not publicly servable; `unknown` faces keep the compatibility declaration
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $fallback,
        public readonly bool $removed,
        public readonly array $faces,
    ) {
    }
}
