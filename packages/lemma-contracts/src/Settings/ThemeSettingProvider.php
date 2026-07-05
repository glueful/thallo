<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Settings;

/**
 * The stored live-theme OVERRIDE for render surfaces (theme-setting spec §2).
 * RAW row semantics: null means "no override stored" — the env/config default
 * applies. Implementations MUST NOT return the resolved effective value (an
 * env fallback masquerading as a stored override would break the clear-to-
 * fallback and revalidation ladders). One source of truth — Settings → General.
 */
interface ThemeSettingProvider
{
    /** The raw stored theme override, or null when none is stored. */
    public function themeOverride(): ?string;
}
