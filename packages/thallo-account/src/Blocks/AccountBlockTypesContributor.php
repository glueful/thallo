<?php

declare(strict_types=1);

namespace Thallo\Account\Blocks;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * Account pack block types: `auth-state`, a conditional-chrome block with two allowlisted,
 * cache-safe child slots (`signed_out` / `signed_in`) toggled client-side by the private
 * `/_account/session` read (see `Thallo\Account\Http\AccountSessionController`). Both slots ship
 * in the shared/cached HTML — `auth-state` is presentation only, never an authorization boundary
 * — so each slot's `block_types` is paired with `enforce_block_types: true`, hard-rejecting any
 * child outside the passive, cache-safe allowlist (no self-hydrating or fetching blocks).
 *
 * The `account-link` block that used to live here was physically retired pre-launch (removed, not
 * migrated — see `thallo:account:retire-account-link`): its definition, template, and palette
 * entries are gone.
 */
final class AccountBlockTypesContributor implements StarterBlockTypeContributor
{
    /** Child blocks a signed_out/signed_in slot may hold — passive, cache-safe, never self-hydrating. */
    private const ALLOWED_CHILD_TYPES = ['button', 'links', 'rich_text', 'logo', 'navigation'];

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
        ];
    }
}
