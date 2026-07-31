<?php

declare(strict_types=1);

namespace Thallo\Account\Blocks;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * Account pack block types.
 *
 * `auth-state` is a conditional-chrome block with two allowlisted, cache-safe child slots
 * (`signed_out` / `signed_in`) toggled client-side by the private `/_account/session` read (see
 * `Thallo\Account\Http\AccountSessionController`). Both slots ship in the shared/cached HTML —
 * presentation only, never an authorization boundary.
 *
 * The three form blocks (`login-form`, `register-form`, `forgot-password-form`) let a dev compose
 * custom versions of the account pages: byte-identical anonymous forms posting to the standard
 * `/account/*` endpoints (same-origin provenance + rate limit — no session CSRF token, so they are
 * safe in the shared page cache). Logout and the transitional verify/reset pages are deliberately
 * NOT blocks: logout is session-CSRF-bound, and the transitional pages are mid-flow surfaces
 * reached by redirect with a cookie.
 *
 * The `account-link` block that used to live here was physically retired pre-launch (removed, not
 * migrated — see `thallo:account:retire-account-link`).
 */
final class AccountBlockTypesContributor implements StarterBlockTypeContributor
{
    /**
     * Child blocks a signed_out/signed_in slot may hold — an EXPLICIT set of vetted, cache-safe
     * blocks (an authorization-independent presentation boundary). Not "passive-only": the
     * account-owned `login-form` carries its own vetted enhancement (`account-forms.js`), which is
     * cache-safe by construction. Anything outside this list is hard-rejected by
     * `enforce_block_types`.
     */
    private const ALLOWED_CHILD_TYPES = [
        'button', 'links', 'rich_text', 'logo', 'navigation',
        'login-form', 'register-form', 'forgot-password-form',
    ];

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-account:auth-state',
                slug: 'auth-state',
                label: 'Account state',
                icon: 'i-lucide-user-round',
                category: 'Account',
                description: 'Shows one set of blocks to signed-out visitors and another to signed-in ones.',
                schema: [
                    [
                        'name' => 'signed_out',
                        'type' => 'blocks',
                        'block_types' => self::ALLOWED_CHILD_TYPES,
                        'enforce_block_types' => true,
                    ],
                    [
                        'name' => 'signed_in',
                        'type' => 'blocks',
                        'block_types' => self::ALLOWED_CHILD_TYPES,
                        'enforce_block_types' => true,
                    ],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-account:login-form',
                slug: 'login-form',
                label: 'Sign-in form',
                icon: 'i-lucide-log-in',
                category: 'Account',
                description: 'The sign-in form, embeddable on any page. A failed attempt returns '
                    . 'to this page with an inline error.',
                schema: [
                    ['name' => 'heading', 'type' => 'string'],
                    // The post-login destination; validated SERVER-SIDE on POST (AccountReturnPath
                    // via safeNext), so a hostile stored value is simply ignored.
                    ['name' => 'next', 'type' => 'string'],
                    ['name' => 'show_links', 'type' => 'boolean'],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-account:register-form',
                slug: 'register-form',
                label: 'Registration form',
                icon: 'i-lucide-user-plus',
                category: 'Account',
                description: 'The create-account form, embeddable on any page. Continues into the '
                    . 'email-verification flow.',
                schema: [
                    ['name' => 'heading', 'type' => 'string'],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-account:forgot-password-form',
                slug: 'forgot-password-form',
                label: 'Password reset request',
                icon: 'i-lucide-key-round',
                category: 'Account',
                description: 'The request-a-reset-code form, embeddable on any page. Continues '
                    . 'into the reset flow.',
                schema: [
                    ['name' => 'heading', 'type' => 'string'],
                ],
            ),
        ];
    }
}
