<?php

declare(strict_types=1);

namespace App\Content\Starter;

interface StarterSeedFailpoint
{
    public function afterMarkActive(string $tenantUuid): void;
}
