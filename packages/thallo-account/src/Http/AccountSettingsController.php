<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Account\AccountReturnPath;
use Thallo\Account\Http\DTOs\Responses\AccountSettingsResultData;
use Thallo\Account\Http\DTOs\UpdateAccountSettingsData;
use Thallo\Account\Settings\AccountSettingsStore;
use Thallo\Contracts\Account\AccountNavigationRegistry;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\PublishedPageDirectory;

/**
 * Read/write the account surface's admin settings: a FIXED, allowlisted inventory of the themed
 * account pages an operator can link to, the two configurable redirect overrides (post-login /
 * post-logout), and curated per-field redirect suggestions. Consumes only the pack's own contracts
 * ({@see AccountSettingsStore}, {@see AccountReturnPath}, {@see AccountNavigationRegistry}) — never
 * an app class. Gated by `thallo.accounts` (route-file load) + `content.manage` (per-route); see
 * `routes/admin-routes.php`.
 *
 * The page inventory is a hardcoded allowlist, never derived from the router — the admin API never
 * exposes arbitrary route inventory.
 */
final class AccountSettingsController
{
    /** @var list<array{label: string, path: string}> The themed account pages, in display order. */
    private const PAGES = [
        ['label' => 'Sign in', 'path' => '/account/login'],
        ['label' => 'Register', 'path' => '/account/register'],
        ['label' => 'Verify email', 'path' => '/account/verify'],
        ['label' => 'Forgot password', 'path' => '/account/forgot-password'],
        ['label' => 'Account dashboard', 'path' => '/account'],
    ];

    public function __construct(
        private readonly AccountSettingsStore $settings,
        private readonly AccountReturnPath $returnPaths,
        private readonly AccountNavigationRegistry $navigation,
        private readonly CapabilityRegistry $capabilities,
        /** Soft-bound: null when no delivery layer is available (published pages simply omitted). */
        private readonly ?PublishedPageDirectory $pages = null,
    ) {
    }

    /** GET /v1/admin/settings/accounts */
    #[ApiOperation(
        summary: 'Get account settings',
        description: 'The allowlisted account-page inventory plus the current post-login/post-logout '
            . 'redirect overrides (null = no override, the fixed default applies). Requires '
            . '`content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, schema: AccountSettingsResultData::class, description: 'Current account settings.')]
    public function show(): Response
    {
        return Response::success($this->payload(), 'Account settings retrieved.');
    }

    /** PUT /v1/admin/settings/accounts */
    #[ApiOperation(
        summary: 'Update account redirects',
        description: 'Persists the post-login/post-logout redirect overrides (a blank field clears '
            . 'that override). Each non-blank value must be a safe application-relative path. '
            . 'Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, schema: AccountSettingsResultData::class, description: 'Settings saved.')]
    #[ApiResponse(422, description: 'A redirect is not a safe application-relative path.')]
    public function update(UpdateAccountSettingsData $input): Response
    {
        $afterLogin = $this->normalize($input->after_login);
        $afterLogout = $this->normalize($input->after_logout);

        $errors = [];
        if ($afterLogin !== null && $this->returnPaths->validate($afterLogin) === null) {
            $errors['after_login'] = 'Must be a safe site-relative path beginning with a single "/".';
        }
        if ($afterLogout !== null && $this->returnPaths->validate($afterLogout) === null) {
            $errors['after_logout'] = 'Must be a safe site-relative path beginning with a single "/".';
        }
        if ($errors !== []) {
            return Response::validation($errors);
        }

        // One atomic save of the pair; a null clears that override (DELETE the row).
        $this->settings->saveRedirects($afterLogin, $afterLogout);

        return Response::success($this->payload(), 'Account settings saved.');
    }

    /**
     * @return array{
     *   pages: list<array{label: string, path: string}>,
     *   after_login: ?string, after_logout: ?string,
     *   suggestions: array{after_login: list<array{label: string, path: string}>,
     *     after_logout: list<array{label: string, path: string}>},
     * }
     */
    private function payload(): array
    {
        return [
            'pages' => self::PAGES,
            'after_login' => $this->settings->afterLogin(),
            'after_logout' => $this->settings->afterLogout(),
            'suggestions' => $this->suggestions(),
        ];
    }

    /**
     * Curated, convenience-only redirect targets per field (the value still passes through
     * {@see AccountReturnPath} on save). Transitional auth-action pages (register / verify / password
     * reset / logout) are deliberately never suggested — they would create a redirect loop or land a
     * visitor in a dead authentication flow. Published site pages (via {@see PublishedPageDirectory},
     * when a delivery layer is bound) are appended to both fields.
     *
     * - after_login: `/account` plus the ENABLED account sections — an authenticated destination.
     * - after_logout: `/` and the sign-in page — the visitor is anonymous again, so no account sections.
     *
     * @return array{after_login: list<array{label: string, path: string}>,
     *   after_logout: list<array{label: string, path: string}>}
     */
    private function suggestions(): array
    {
        $afterLogin = [['label' => 'Account', 'path' => '/account']];
        foreach ($this->navigation->items() as $item) {
            if ($item->capability === null || $this->capabilities->isEnabled($item->capability)) {
                $afterLogin[] = ['label' => $item->label, 'path' => $item->path];
            }
        }

        $afterLogout = [
            ['label' => 'Home', 'path' => '/'],
            ['label' => 'Sign in', 'path' => '/account/login'],
        ];

        // Published site pages are valid post-auth destinations for BOTH fields — e.g. a custom
        // landing or thank-you page an author created. Convenience only; validated on save.
        $pages = $this->pages?->publicPages() ?? [];

        return [
            'after_login' => array_merge($afterLogin, $pages),
            'after_logout' => array_merge($afterLogout, $pages),
        ];
    }

    /** Trim, and treat a blank value as "clear this override" (null). */
    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
