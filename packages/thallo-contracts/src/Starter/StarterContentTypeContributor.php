<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * A pack's seam into the starter content-type set: implement this and register an instance with
 * {@see StarterContributorRegistry} (resolved via the interface — the concrete registry is
 * app-owned) to have additional content types created alongside the fixed pages/category/post
 * set on fresh-tenant provisioning and adopted into existing tenants via `thallo:tenant:sync`.
 */
interface StarterContentTypeContributor
{
    /** @return list<StarterContentTypeDefinition> */
    public function contentTypeDefinitions(): array;
}
