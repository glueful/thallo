<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * The capability switchboard shows this pack to operators. "Storefront accounts" read as a
 * commerce feature; it is the site's visitor accounts (registration, sign-in, account pages),
 * present with or without a shop.
 */
final class AccountCapabilityLabelTest extends AppTestCase
{
    public function testTheCapabilityIsLabelledAccounts(): void
    {
        $registry = $this->container()->get(CapabilityRegistry::class);
        $accounts = array_values(array_filter(
            $registry->all(),
            static fn (Capability $c): bool => $c->id === 'thallo.accounts',
        ));

        self::assertCount(1, $accounts);
        self::assertSame('Accounts', $accounts[0]->label);
        self::assertStringContainsString('visitors', (string) $accounts[0]->description);
    }
}
