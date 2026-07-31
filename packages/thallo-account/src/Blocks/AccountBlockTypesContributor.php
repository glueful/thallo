<?php

declare(strict_types=1);

namespace Thallo\Account\Blocks;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * Account pack block types (currently none; auth-state added later). The `account-link` block
 * that used to live here was physically retired pre-launch (removed, not migrated — see
 * `thallo:account:retire-account-link`): its definition, template, and palette entries are gone.
 */
final class AccountBlockTypesContributor implements StarterBlockTypeContributor
{
    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [];
    }
}
