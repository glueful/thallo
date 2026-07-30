<?php

declare(strict_types=1);

namespace Thallo\Account\Blocks;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * The `account-link` starter block type — a header/footer link that shows "Sign in" to everyone and
 * is hydrated client-side into the visitor's name. It declares NO template path: RenderContextExtension
 * always resolves `blocks/{type}.twig` against the theme chain, so the template must live at
 * `templates/blocks/account-link.twig`. Mirrors `Thallo\Commerce\Starter\ShopBlockTypesContributor`.
 */
final class AccountBlockTypesContributor implements StarterBlockTypeContributor
{
    public const SLUG_ACCOUNT_LINK = 'account-link';

    private const CATEGORY = 'Account';

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-account:' . self::SLUG_ACCOUNT_LINK,
                slug: self::SLUG_ACCOUNT_LINK,
                label: 'Account link',
                icon: 'i-lucide-user',
                category: self::CATEGORY,
                description: 'A sign-in link that shows the signed-in visitor\'s name — hydrated '
                    . 'client-side, so per-visitor identity never enters a shared page cache.',
                schema: [
                    ['name' => 'label', 'type' => 'string'],
                ],
            ),
        ];
    }
}
