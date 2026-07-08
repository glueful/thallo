<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Verified preview-session claims + the ORIGINAL token (the render layer builds
 * /_preview-assets/{token}/… URLs from it). Produced only by PreviewSessionVerifier —
 * holding an instance MEANS the signature and expiry were checked. Immutable.
 */
final class PreviewSession
{
    public function __construct(
        public readonly string $token,
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $version,
        public readonly ?string $theme,
        /** theme-color-config spec §6: previewed accent/neutral families (null = none). */
        public readonly ?string $accent,
        public readonly ?string $neutral,
        public readonly int $expiresAt,
    ) {
    }
}
