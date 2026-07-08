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
}
