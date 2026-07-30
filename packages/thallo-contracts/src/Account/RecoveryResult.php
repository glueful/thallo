<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The outcome of a recovery request, as far as any caller may know: accepted.
 *
 * There is deliberately no field for "unknown email" or "delivery failed". Verification proves
 * nothing about whether an address is registered here, and a storefront that leaked the
 * difference would be an account-existence oracle. Operational failures are logged, not returned.
 */
final class RecoveryResult
{
    public function __construct(public readonly bool $accepted)
    {
    }
}
