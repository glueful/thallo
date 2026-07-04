<?php

declare(strict_types=1);

namespace App\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PUT /v1/admin/settings/general`
 * ({@see \App\Http\Controllers\GeneralSettingsController::update()}).
 *
 * Partial update — every field is optional; only non-null fields are written to `.env`. Hydrated +
 * format-validated by the router; cross-field rules (max ≥ default, clamping) stay in the controller.
 */
final class UpdateGeneralSettingsData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $site_name = null,
        #[Rule('string')]
        public readonly ?string $site_preview_url = null,
        #[Rule('string')]
        public readonly ?string $default_locale = null,
        #[Rule('numeric')]
        public readonly ?int $default_per_page = null,
        #[Rule('numeric')]
        public readonly ?int $max_per_page = null,
        #[Rule('numeric')]
        public readonly ?int $cache_ttl = null,
        #[Rule('boolean')]
        public readonly ?bool $scheduler_enabled = null,
        #[Rule('boolean')]
        public readonly ?bool $webhooks_enabled = null,
        /** Entry uuid rendered at `/`; EXPLICIT '' clears to the env fallback. */
        #[Rule('string')]
        public readonly ?string $homepage_entry = null,
    ) {
    }
}
