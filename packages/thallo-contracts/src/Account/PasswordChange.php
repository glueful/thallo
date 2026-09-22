<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The outcome of a password change. `changed` carries the session that replaces the caller's
 * (every earlier one is revoked); the refusals carry a message for the form.
 */
final class PasswordChange
{
    public const CHANGED = 'changed';
    public const WRONG_PASSWORD = 'wrong_password';
    public const WEAK_PASSWORD = 'weak_password';
    public const FAILED = 'failed';

    /** @param array<string,mixed>|null $session */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly ?array $session = null,
    ) {
    }

    public function changed(): bool
    {
        return $this->status === self::CHANGED;
    }
}
