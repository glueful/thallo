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

/**
 * Read/write the storefront account surface's admin settings: a FIXED, allowlisted inventory of the
 * themed account pages an operator can link to, plus the two configurable redirect overrides
 * (post-login / post-logout). Consumes only the pack's own {@see AccountSettingsStore} and
 * {@see AccountReturnPath} — never an app class. Gated by `thallo.accounts` (route-file load) +
 * `content.manage` (per-route); see `routes/admin-routes.php`.
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

    /** @return array{pages: list<array{label: string, path: string}>, after_login: ?string, after_logout: ?string} */
    private function payload(): array
    {
        return [
            'pages' => self::PAGES,
            'after_login' => $this->settings->afterLogin(),
            'after_logout' => $this->settings->afterLogout(),
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
