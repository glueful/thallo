<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/** The workspace's palette (custom palette spec §2), read by render and by the palette services. */
interface PaletteProvider
{
    public function palette(): Palette;
}
