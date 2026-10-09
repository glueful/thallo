<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/** The workspace's palette (custom palette spec §2), read by render and by the palette services. */
interface PaletteProvider
{
    public function palette(): Palette;

    /**
     * The palette an Appearance preview claims (custom palette spec §5.1): each key it carries
     * replaces the stored one; a key it omits — or a brand slot it omits — keeps the stored value.
     *
     * @param array<string,mixed> $claim
     */
    public function preview(array $claim): Palette;
}
