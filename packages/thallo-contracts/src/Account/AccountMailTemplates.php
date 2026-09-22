<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The email templates the account pack registers for the site's customers. The app sends a
 * customer's verification and password-reset mail through them while they are registered, and
 * through the mail extension's built-in `verification` and `password-reset` otherwise.
 */
final class AccountMailTemplates
{
    public const VERIFICATION = 'account.verification';
    public const PASSWORD_RESET = 'account.password_reset';
}
