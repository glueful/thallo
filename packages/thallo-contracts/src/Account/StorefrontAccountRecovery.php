<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * Storefront password recovery, as the account pack consumes it.
 *
 * The result types cannot express "unknown email" or "delivery failed": a storefront must not
 * become an account-existence oracle, and it cannot inherit a neutrality a host config
 * (`security.auth.generic_error_responses`) can switch off. The app glue collapses every outcome.
 */
interface StorefrontAccountRecovery
{
    public function begin(string $email, string $ip): RecoveryResult;

    public function verify(string $email, string $otp): RecoveryVerification;

    public function complete(string $resetToken, string $newPassword): RecoveryResult;
}
