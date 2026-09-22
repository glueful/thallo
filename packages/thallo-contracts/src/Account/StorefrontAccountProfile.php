<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * A signed-in customer's own profile, as the account pack consumes it: read it, rename, and
 * change the password. Every method acts on the uuid the caller's session proved; none looks an
 * account up by email.
 */
interface StorefrontAccountProfile
{
    /** Null when the account no longer exists or is not active. */
    public function profile(string $userUuid): ?AccountProfile;

    /** Field errors (`first_name`, `last_name`) when refused; empty when saved. */
    public function rename(string $userUuid, string $firstName, string $lastName): ProfileUpdate;

    /**
     * Checks the current password, applies the shared password rule, writes the new one, and
     * signs out every session the account has. The result carries a fresh session for the
     * device that asked, so it stays signed in.
     */
    public function changePassword(string $userUuid, string $currentPassword, string $newPassword): PasswordChange;
}
