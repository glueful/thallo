<?php

declare(strict_types=1);

namespace App\Settings;

use Glueful\Lemma\Contracts\Settings\SiteLogoProvider;

/** GeneralSettings-backed site logo (block-library spec §2). */
final class EngineSiteLogoProvider implements SiteLogoProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function siteLogoUuid(): ?string
    {
        $uuid = $this->settings->siteLogo();
        return $uuid === '' ? null : $uuid;
    }
}
