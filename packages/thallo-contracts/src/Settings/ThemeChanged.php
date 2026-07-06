<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

use Glueful\Events\Contracts\BaseEvent;

/**
 * The live theme setting changed (theme-setting spec §5). Cross-pack seam,
 * the RegionUpdated posture: the app's settings save dispatches it (only when
 * the STORED override actually changed); thallo-render purges its page cache
 * via invalidateTags(['lemma:render:page']) — the theme touches every page
 * AND the themed error bodies.
 */
final class ThemeChanged extends BaseEvent
{
    public function __construct(public readonly string $theme)
    {
        parent::__construct();
    }
}
