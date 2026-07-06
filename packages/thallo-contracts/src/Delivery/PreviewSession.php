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
        public readonly int $expiresAt,
    ) {
    }
}
