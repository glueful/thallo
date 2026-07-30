<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * Storefront customer registration, as the account pack consumes it. Implemented by the app over
 * its signup pipeline; the pack never imports `App\Signup`.
 */
interface StorefrontAccountRegistration
{
    public function begin(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        string $ip,
    ): RegistrationResult;

    public function resend(string $intentUuid, string $ip): void;

    public function verify(string $intentUuid, string $otp): RegistrationResult;
}
