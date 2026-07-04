<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\DTOs\Responses\GeneralSettingsResultData;
use App\Http\DTOs\UpdateGeneralSettingsData;
use App\Settings\GeneralSettings;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Read/write the instance "General" settings — site identity, default locale, content-delivery
 * defaults, and feature toggles.
 *
 * Backed by the `lemma_settings` table via {@see GeneralSettings}: a stored row overrides the
 * deploy-time config/.env default, so a save takes effect on the next request across every instance
 * with no restart (unlike the `.env`-backed email settings). Gated by `content.manage` — see
 * routes/lemma_admin.php.
 */
final class GeneralSettingsController
{
    private const PER_PAGE_CAP = 1000;

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly ?\Glueful\Lemma\Contracts\Delivery\PublicRouteResolver $resolver = null,
        private readonly ?\App\Content\Repositories\ContentTypeRepository $contentTypes = null,
    ) {
    }

    /** GET /v1/admin/settings/general */
    #[ApiOperation(
        summary: 'Get general settings',
        description: 'Effective instance settings (site identity, default locale, delivery defaults, '
            . 'feature toggles): a lemma_settings override, else the config/.env default. Requires '
            . '`content.manage`.',
        tags: ['Lemma Settings'],
    )]
    #[ApiResponse(200, schema: GeneralSettingsResultData::class, description: 'Current general settings.')]
    public function show(): Response
    {
        return Response::success(['settings' => $this->settings->all()], 'General settings retrieved.');
    }

    /** PUT /v1/admin/settings/general */
    #[ApiOperation(
        summary: 'Update general settings',
        description: 'Persists the submitted settings to lemma_settings (only supplied fields change). '
            . 'Applies on the next request — no restart. Requires `content.manage`.',
        tags: ['Lemma Settings'],
    )]
    #[ApiResponse(200, schema: GeneralSettingsResultData::class, description: 'Settings saved.')]
    #[ApiResponse(422, description: 'Invalid value (non-positive page size, max < default, …).')]
    public function update(UpdateGeneralSettingsData $input): Response
    {
        $errors = $this->validate($input);
        if ($errors !== []) {
            return Response::validation($errors);
        }

        $this->settings->save([
            'site_name' => $input->site_name,
            'site_preview_url' => $input->site_preview_url,
            'default_locale' => $input->default_locale,
            'default_per_page' => $input->default_per_page,
            'max_per_page' => $input->max_per_page,
            'cache_ttl' => $input->cache_ttl,
            'scheduler_enabled' => $input->scheduler_enabled,
            'webhooks_enabled' => $input->webhooks_enabled,
            'homepage_entry' => $input->homepage_entry,
            'site_logo' => $input->site_logo,
            'listing_types' => $input->listing_types,
        ]);

        return Response::success(
            ['settings' => $this->settings->all()],
            'General settings saved.',
        );
    }

    /**
     * Cross-field validation against the effective (current + submitted) values.
     *
     * @return array<string,string>
     */
    private function validate(UpdateGeneralSettingsData $input): array
    {
        $errors = [];

        if ($input->default_per_page !== null && $input->default_per_page < 1) {
            $errors['default_per_page'] = 'Must be at least 1.';
        }
        if ($input->max_per_page !== null && ($input->max_per_page < 1 || $input->max_per_page > self::PER_PAGE_CAP)) {
            $errors['max_per_page'] = 'Must be between 1 and ' . self::PER_PAGE_CAP . '.';
        }
        if ($input->cache_ttl !== null && $input->cache_ttl < 0) {
            $errors['cache_ttl'] = 'Cannot be negative (0 disables caching).';
        }

        // max_per_page must stay ≥ default_per_page (check the effective values).
        $current = $this->settings->all();
        $effDefault = $input->default_per_page ?? (int) $current['default_per_page'];
        $effMax = $input->max_per_page ?? (int) $current['max_per_page'];
        if (!isset($errors['default_per_page'], $errors['max_per_page']) && $effMax < $effDefault) {
            $errors['max_per_page'] = 'Max per page must be greater than or equal to the default.';
        }

        // Homepage (homepage-setting spec §0): write-time validation, never a
        // runtime surprise — the uuid must resolve to PUBLISHED content of a
        // publicly delivered type RIGHT NOW. '' (clear) and null (unchanged)
        // skip the check.
        if (
            $input->homepage_entry !== null
            && $input->homepage_entry !== ''
            && ($this->resolver === null
                || ($this->resolver->resolveEntry($input->homepage_entry)['kind'] ?? null) !== 'content')
        ) {
            $errors['homepage_entry'] =
                'must be a published entry of a publicly delivered content type';
        }

        // Listing types must NAME REAL content types (typo protection); the
        // public/non-public state stays a render-time gate, not a write gate —
        // a listed non-public type is dormant until its flag flips.
        if ($input->listing_types !== null && $this->contentTypes !== null) {
            foreach ($input->listing_types as $slug) {
                if ($this->contentTypes->findBySlug((string) $slug) === null) {
                    $errors['listing_types'] = "unknown content type '{$slug}'";
                    break;
                }
            }
        }

        return $errors;
    }
}
