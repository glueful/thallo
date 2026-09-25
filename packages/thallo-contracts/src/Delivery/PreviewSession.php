<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Verified preview-session claims + the ORIGINAL token (the render layer builds
 * /_thallo/preview-assets/{token}/… URLs from it). Produced only by PreviewSessionVerifier —
 * holding an instance MEANS the signature and expiry were checked. Immutable.
 *
 * Two kinds (regions-stage spec §4.1): an `entry` session previews one entry's draft; a `regions`
 * session is the header & footer stage — `entry` is empty, `session` keys its records and `page`
 * names the published page shown between the chrome. Every consumer that reads drafts or
 * annotates an entry acts on `entry` sessions only.
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
        /**
         * Previewed design settings (radius, font, background), any subset; null = none.
         *
         * @var array<string,string>|null
         */
        public readonly ?array $design = null,
        public readonly string $kind = self::KIND_ENTRY,
        public readonly ?string $session = null,
        public readonly ?string $page = null,
    ) {
    }

    public const KIND_ENTRY = 'entry';
    public const KIND_REGIONS = 'regions';

    public function isEntry(): bool
    {
        return $this->kind === self::KIND_ENTRY;
    }
}
