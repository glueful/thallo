<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The unscoped system-settings channel — a string key/value store for install-and-runtime flags
 * that must be readable before tenant resolution and must NEVER be tenant-scoped (e.g. `installed`,
 * `scheduler_enabled`, `webhooks_enabled`, `admin_url`).
 *
 * Distinct from the `settings` table (per-site, soon tenant-scoped): system keys are routed here so
 * enabling multi-tenancy cannot fragment or lose them. Implemented by the tenancy pack's SystemFlags.
 */
interface SystemChannel
{
    public function get(string $key): ?string;

    public function put(string $key, string $value): void;

    public function forget(string $key): void;
}
