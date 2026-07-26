<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Registry of the settings keys that belong to the unscoped system channel
 * ({@see \Thallo\Contracts\Settings\SystemChannel}) rather than the per-site `settings` table.
 *
 * These are install-and-runtime flags that must be readable before tenant resolution and must never
 * be tenant-scoped. Everything not listed here stays in the (soon tenant-scoped) `settings` table.
 */
final class SystemKeys
{
    /** @var list<string> */
    public const KEYS = [
        'installed',
        'scheduler_enabled',
        'webhooks_enabled',
        // The thallo.search capability switch: read by makeCapabilityRegistry() at BOOT
        // (before tenant resolution) and it gates instance-global route registration —
        // a tenant-scoped row would both throw under enforcement and fragment per
        // workspace what is physically one switch.
        'search_enabled',
        'admin_url',
    ];

    public static function isSystem(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
