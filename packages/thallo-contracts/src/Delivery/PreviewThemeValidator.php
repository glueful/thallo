<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Theme-name validation for per-preview themes (preview-sessions spec §5). Implemented
 * by the render pack with the REAL theme-ladder semantics; core's mint endpoint
 * consults it only if bound — a supplied theme with no validator bound is invalid
 * (the render pack is absent, so no theme could ever render).
 */
interface PreviewThemeValidator
{
    public function isValidTheme(string $name): bool;
}
