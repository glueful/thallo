<?php

declare(strict_types=1);

namespace App\Settings;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Effective instance "General" settings: a `lemma_settings` row overrides the deploy-time
 * config/.env default. Precedence: DB row → `config('lemma.*')` (which reads .env) → hard default.
 *
 * This is the single read point for these settings so a save (to `lemma_settings`) takes effect on
 * the next request across every instance, with no `.env` rewrite or restart. Consumers call e.g.
 * `app($context, GeneralSettings::class)->maxPerPage()` instead of `config('lemma.delivery.max_per_page')`.
 */
final class GeneralSettings
{
    /** Setting key => [config path used as the deploy-time default, value type, hard fallback]. */
    private const DEFS = [
        'site_name'         => ['lemma.site_name', 'string', 'Lemma'],
        'site_preview_url'  => ['lemma.admin.site_preview_url', 'string', ''],
        'default_locale'    => ['lemma.admin.default_locale', 'string', 'en'],
        'default_per_page'  => ['lemma.delivery.default_per_page', 'int', 20],
        'max_per_page'      => ['lemma.delivery.max_per_page', 'int', 100],
        'cache_ttl'         => ['lemma.delivery.cache_ttl', 'int', 60],
        'scheduler_enabled' => ['lemma.scheduler.enabled', 'bool', true],
        'webhooks_enabled'  => ['lemma.pipeline.webhooks_enabled', 'bool', true],
        'homepage_entry'    => ['lemma_render.homepage_entry', 'string', ''],
        'site_logo'         => ['lemma.site_logo', 'string', ''],
        // Which content types expose /{type} listings + /{type}/{field}/{term}
        // archives. DB row wins (CSV; '' = explicitly none); config/.env is the
        // pre-first-save deploy default.
        'listing_types'     => ['lemma_render.listing_types', 'list', []],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SettingsStore $store,
    ) {
    }

    public function siteName(): string
    {
        return (string) $this->value('site_name');
    }

    /** The effective listing-types allowlist (render grammar gate). @return list<string> */
    public function listingTypes(): array
    {
        return array_values(array_filter(array_map(strval(...), (array) $this->value('listing_types'))));
    }

    /** Asset uuid of the site logo; '' when unset (blocks fall back to the site name). */
    public function siteLogo(): string
    {
        return (string) $this->value('site_logo');
    }

    public function sitePreviewUrl(): string
    {
        return (string) $this->value('site_preview_url');
    }

    public function defaultLocale(): string
    {
        return (string) $this->value('default_locale');
    }

    /** The RAW stored homepage override — null when no DB row (env applies). */
    public function homepageEntryOverride(): ?string
    {
        return $this->store->get('homepage_entry');
    }

    public function defaultPerPage(): int
    {
        return (int) $this->value('default_per_page');
    }

    public function maxPerPage(): int
    {
        return (int) $this->value('max_per_page');
    }

    public function cacheTtl(): int
    {
        return (int) $this->value('cache_ttl');
    }

    public function schedulerEnabled(): bool
    {
        return (bool) $this->value('scheduler_enabled');
    }

    public function webhooksEnabled(): bool
    {
        return (bool) $this->value('webhooks_enabled');
    }

    /**
     * The effective settings (for the admin General page).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFS) as $key) {
            $out[$key] = $this->value($key);
        }

        return $out;
    }

    /**
     * Persist the supplied settings (only keys present and non-null are written).
     *
     * @param array<string,mixed> $partial
     */
    public function save(array $partial): void
    {
        $pairs = [];
        foreach (self::DEFS as $key => [$cfg, $type, $def]) {
            if (array_key_exists($key, $partial) && $partial[$key] !== null) {
                // homepage_entry (homepage-setting spec §0): an EXPLICIT empty
                // string means "clear to fallback" — the row is DELETED so the
                // config/.env value shows through (a stored '' would shadow it).
                // null keeps the usual "unchanged" meaning.
                if ($key === 'homepage_entry' && $partial[$key] === '') {
                    $this->store->forget($key);
                    continue;
                }
                $pairs[$key] = $this->encode($partial[$key], $type);
            }
        }
        $this->store->putMany($pairs);
    }

    private function value(string $key): mixed
    {
        [$cfg, $type, $def] = self::DEFS[$key];
        $raw = $this->store->get($key);
        if ($raw === null) {
            // No override stored — fall back to the deploy-time config/.env value.
            return config($this->context, $cfg, $def);
        }

        return $this->decode($raw, $type);
    }

    private function decode(string $raw, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $raw,
            'bool' => in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true),
            'list' => $raw === '' ? [] : array_values(array_filter(array_map(trim(...), explode(',', $raw)))),
            default => $raw,
        };
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'int' => (string) (int) $value,
            'bool' => $value ? 'true' : 'false',
            'list' => implode(',', array_values(array_filter(array_map(
                static fn(mixed $v): string => trim((string) $v),
                (array) $value,
            )))),
            default => trim((string) $value),
        };
    }
}
