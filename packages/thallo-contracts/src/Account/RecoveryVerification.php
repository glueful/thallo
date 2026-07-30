<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The result of exchanging an emailed recovery OTP for a reset token.
 *
 * `verified: false` covers BOTH a wrong code and an unknown address — the two are
 * indistinguishable, so verification leaks nothing. The token is present only when verified.
 */
final class RecoveryVerification
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $resetToken,
    ) {
    }
}
