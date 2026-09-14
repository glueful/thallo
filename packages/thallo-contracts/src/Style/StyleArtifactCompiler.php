<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * Compiles and publishes a theme's compiled style artifact (visual builder spec §2.4). The one
 * seam provision and a theme switch compile through: the artifact is published before anything
 * links it, and a failure throws {@see StyleCompileFailed} leaving the previous artifact, and the
 * previously active theme, in place. Implemented by the render pack; core treats an unbound
 * compiler as "no renderer, nothing to compile".
 */
interface StyleArtifactCompiler
{
    /**
     * @param string|null $theme the theme to compile; null = the active theme
     * @return string the published artifact's hash
     * @throws StyleCompileFailed
     */
    public function compile(?string $theme = null): string;
}
