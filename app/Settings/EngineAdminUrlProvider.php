<?php

declare(strict_types=1);

namespace App\Settings;

use Glueful\Lemma\Contracts\Settings\AdminUrlProvider;

/** GeneralSettings-backed admin URL (admin-bar feature). */
final class EngineAdminUrlProvider implements AdminUrlProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function adminUrl(): ?string
    {
        $url = $this->settings->adminUrl();
        return $url === '' ? null : $url;
    }
}
