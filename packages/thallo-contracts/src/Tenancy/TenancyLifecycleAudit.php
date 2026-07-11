<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

interface TenancyLifecycleAudit
{
    /** @param array<string, mixed> $context */
    public function record(string $action, ?string $actorUuid, ?string $tenantUuid, array $context = []): void;
}
