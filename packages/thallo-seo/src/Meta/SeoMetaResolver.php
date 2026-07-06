<?php

declare(strict_types=1);

namespace Thallo\Seo\Meta;

use Thallo\Contracts\Delivery\ContentDeliveryReader;

/**
 * Resolves the SEO meta for a published entry: per-entry override → per-type fallback
 * field → site default. Carries no absolute URLs (canonical/hreflang live on the core
 * delivery `seo` object).
 */
final class SeoMetaResolver
{
    /**
     * @param callable(string,string):(?array<string,mixed>) $overrideFor  (entryUuid, locale) => seo_meta row|null
     * @param array<string,array{title_field?:string,description_field?:string,image_field?:string}> $fallbacks
     * @param array{site_name:string,default_og_image:string,title_template:string} $defaults
     */
    public function __construct(
        private readonly ContentDeliveryReader $reader,
        private readonly mixed $overrideFor,
        private readonly array $fallbacks,
        private readonly array $defaults,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $typeUuid, string $typeSlug, string $slug, string $locale): ?array
    {
        $entry = $this->reader->findPublished($typeUuid, $locale, $slug);
        if ($entry === null) {
            return null;
        }

        /** @var array<string,mixed> $fields */
        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $override = ($this->overrideFor)((string) ($entry['entry_uuid'] ?? ''), $locale) ?? [];
        $map = $this->fallbacks[$typeSlug] ?? [];

        $description = $this->pick($override, 'description', $fields, $map['description_field'] ?? null);
        $image = $this->pick($override, 'og_image', $fields, $map['image_field'] ?? null)
            ?? ($this->defaults['default_og_image'] !== '' ? $this->defaults['default_og_image'] : null);

        // Title: an explicit override is verbatim (the editor chose it); a title derived from
        // a content field gets the site title_template; absent both, the site name.
        $overrideTitle = $this->overrideString($override, 'title');
        $fieldTitle = $this->fieldString($fields, $map['title_field'] ?? null);
        if ($overrideTitle !== null) {
            $title = $overrideTitle;
        } elseif ($fieldTitle !== null) {
            $title = $this->applyTemplate($fieldTitle);
        } else {
            $title = $this->defaults['site_name'];
        }

        // overrideString() so an empty-string OG override falls back like title/description do.
        $ogTitle = $this->overrideString($override, 'og_title') ?? $title;
        $ogDescription = $this->overrideString($override, 'og_description') ?? $description;

        return [
            'title' => $title,
            'description' => $description,
            'og' => [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'image' => $image,
            ],
            'twitter' => [
                'card' => $override['twitter_card'] ?? null,
            ],
            'robots' => (string) ($override['robots'] ?? 'index'),
        ];
    }

    /**
     * @param array<string,mixed> $override
     * @param array<string,mixed> $fields
     */
    private function pick(array $override, string $overrideKey, array $fields, ?string $fallbackField): ?string
    {
        return $this->overrideString($override, $overrideKey)
            ?? $this->fieldString($fields, $fallbackField);
    }

    /** @param array<string,mixed> $override */
    private function overrideString(array $override, string $key): ?string
    {
        $v = $override[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @param array<string,mixed> $fields */
    private function fieldString(array $fields, ?string $field): ?string
    {
        if ($field === null) {
            return null;
        }
        $v = $fields[$field] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function applyTemplate(string $title): string
    {
        return strtr($this->defaults['title_template'], [
            '{title}' => $title,
            '{site_name}' => $this->defaults['site_name'],
        ]);
    }
}
