<?php

declare(strict_types=1);

namespace App\Content\Starter;

final class SeedContext
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $tenantName,
        public readonly string $defaultLocale,
        public readonly ?string $actorUuid,
    ) {
    }
}
