<?php

declare(strict_types=1);

namespace Thallo\Account\Settings;

/**
 * The pack-owned storage contract for the account surface's redirect settings (public-account-
 * surface plan Task 3). The HOST app binds an implementation over its instance-settings store —
 * pack-defines/app-provides, the same direction as thallo-commerce's `CommerceSettingsStore`, so
 * this package never names an app class (a boundary the pack-boundary check enforces).
 *
 * Values are application-relative redirect paths that have already passed {@see \Thallo\Account\
 * AccountReturnPath}; null means "no override configured" and the caller falls back to a fixed
 * default.
 */
interface AccountSettingsStore
{
    /** The configured post-login redirect path, or null when no override is stored. */
    public function afterLogin(): ?string;

    /** The configured post-logout redirect path, or null when no override is stored. */
    public function afterLogout(): ?string;

    /**
     * Persist both redirect overrides ATOMICALLY: a null argument clears that override (DELETE the
     * row so the fixed default shows through). The two writes land together or not at all, so the
     * one save call can never leave a half-applied pair.
     */
    public function saveRedirects(?string $afterLogin, ?string $afterLogout): void;
}
