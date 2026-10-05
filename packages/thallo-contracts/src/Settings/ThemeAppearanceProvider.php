<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The stored theme-appearance selection for render surfaces (theme-color-config
 * spec §4). Returns the EFFECTIVE saved-or-default family names (unlike
 * ThemeSettingProvider's raw-override posture): render only needs the value to
 * skin tokens, and the default is a real render input, not an env ladder.
 */
interface ThemeAppearanceProvider
{
    /** The saved accent family, or the default when none is stored. */
    public function accent(): string;

    /** The saved neutral family, or the default when none is stored. */
    public function neutral(): string;

    /** Corner radius scale: sharp | soft | round (the default). */
    public function radius(): string;

    /** Typeface pairing: sans (the default), a system pairing, or `custom` (from the font library). */
    public function font(): string;

    /**
     * The `custom` pairing's Text and Headings (block typeface spec §2.8): font library IDs — a
     * built-in or an uploaded family; a role with no family is absent.
     *
     * @return array{text?: string, headings?: string}
     */
    public function fontFamilies(): array;

    /** Page ground: plain (the default, white) | tinted (the neutral's surface tint). */
    public function background(): string;
}
