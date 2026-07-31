<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * Resolves a signed-in visitor's account email from their authenticated user uuid (checkout-ui
 * plan Task 3). JWT attributes deliberately carry NO email, so an uncached page that wants to
 * prefill contact details (the checkout) reads it through this seam — pack-defines/app-provides:
 * the host implements it over its user store; packs never reach into `App\` or assume email
 * exists in claims. Always null-safe: an unknown uuid, a deleted user, or any lookup failure
 * resolves to null and the caller renders the anonymous experience.
 */
interface StorefrontAccountIdentityReader
{
    public function emailFor(string $userUuid): ?string;
}
