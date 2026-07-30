<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The outcome of a registration step.
 *
 * `begin()` and `resend()` leave `pendingVerification` true with the intent to verify; `verify()`
 * resolves it to a created identity (or, for an existing-account handoff, no user). The intent
 * uuid and user uuid are opaque to the caller — a storefront never derives meaning from them.
 */
final class RegistrationResult
{
    public function __construct(
        public readonly bool $pendingVerification,
        public readonly ?string $intentUuid,
        public readonly ?string $userUuid,
    ) {
    }
}
