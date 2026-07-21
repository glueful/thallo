<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * A pack's seam into the starter block-type set: implement this and register an instance with
 * {@see StarterBlockTypeRegistry} (resolved via the interface — the concrete registry is
 * app-owned) to have additional block types created alongside the fixed library on fresh-tenant
 * provisioning and adopted into existing tenants via `thallo:tenant:sync --kind=block_type`.
 */
interface StarterBlockTypeContributor
{
    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array;
}
