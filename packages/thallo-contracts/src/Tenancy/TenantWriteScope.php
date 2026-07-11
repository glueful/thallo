<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

/** Resolves the tenant identifier required by a tenant-bearing write. */
interface TenantWriteScope
{
    /** @return 'normal'|'compat'|'off' */
    public function mode(): string;

    public function tenantUuidForWrite(): ?string;
}
