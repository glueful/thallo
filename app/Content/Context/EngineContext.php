<?php

declare(strict_types=1);

namespace App\Content\Context;

use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Seo\CanonicalPathBuilder;
use App\Settings\GeneralSettings;
use Thallo\Contracts\Context\Context;

final class EngineContext implements Context
{
    public function __construct(
        private readonly ContentLocaleService $locales,
        private readonly GeneralSettings $settings,
        private readonly CanonicalPathBuilder $pathBuilder,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->locales->default();
    }

    public function enabledLocales(): array
    {
        return array_values($this->locales->enabled());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->all()[$key] ?? $default;
    }

    public function renderPath(string $contentTypeSlug, string $locale, string $slug): string
    {
        // Canonical form: root-collapsed for root-mounted types, default
        // locale collapsed — the same builder every href surface uses.
        $type = $this->types->findBySlug($contentTypeSlug);
        return $this->pathBuilder->pathFor(
            $contentTypeSlug,
            (bool) ($type['mount_at_root'] ?? false),
            $locale,
            $slug,
        );
    }
}
