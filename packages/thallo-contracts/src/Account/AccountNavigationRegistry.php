<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * Collects the account-area navigation entries packs contribute. The account shell reads
 * {@see items()} — sorted by `order` — so a pack adds an orders or addresses section without the
 * shell knowing about it.
 */
interface AccountNavigationRegistry
{
    public function register(AccountNavigationItem $item): void;

    /** @return list<AccountNavigationItem> ordered by `order` ascending */
    public function items(): array;
}
