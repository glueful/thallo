<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The second step of a storefront sign-in for an account with two-factor on, as the account pack
 * consumes it: the challenge token login handed out plus the code the visitor was emailed.
 */
interface StorefrontTwoFactor
{
    /**
     * The session the completed login issues (the same array a plain login returns), or null when
     * the token or code is wrong or expired, or the challenge is not a login challenge.
     *
     * @return array<string, mixed>|null
     */
    public function completeLogin(string $challengeToken, string $code): ?array;
}
