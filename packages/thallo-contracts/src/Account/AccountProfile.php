<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/** What a customer's profile page shows. */
final class AccountProfile
{
    public function __construct(
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
    ) {
    }
}
