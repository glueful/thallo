<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * One entry in the storefront account area's navigation. Packs contribute these so the account
 * shell renders orders, addresses and wishlist sections without the account pack importing any of
 * them. `capability` names the capability that must be enabled for the entry to show, or null for
 * an always-present entry.
 */
final class AccountNavigationItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $path,
        public readonly int $order,
        public readonly ?string $capability = null,
    ) {
    }
}
