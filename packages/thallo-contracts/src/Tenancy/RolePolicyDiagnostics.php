<?php

declare(strict_types=1);

namespace Thallo\Contracts\Tenancy;

interface RolePolicyDiagnostics
{
    /** @return list<array{tenant_uuid:string,role_slug:string,capability:string}> */
    public function driftRows(): array;
}
