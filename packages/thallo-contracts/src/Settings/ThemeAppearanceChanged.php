<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

use Glueful\Events\Contracts\BaseEvent;

/**
 * The theme color config (accent/neutral) changed (theme-color-config spec §7).
 * The app's settings save dispatches it only when a STORED value actually
 * changed; thallo-render purges its page cache via
 * invalidateTags(['thallo:render:page']) — the same broad tag class as
 * ThemeChanged, since color config touches every page.
 */
final class ThemeAppearanceChanged extends BaseEvent
{
    public function __construct(
        public readonly string $accent,
        public readonly string $neutral,
    ) {
        parent::__construct();
    }
}
