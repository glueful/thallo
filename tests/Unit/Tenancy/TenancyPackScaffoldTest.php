<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\TenancyServiceProvider;

final class TenancyPackScaffoldTest extends TestCase
{
    public function testProviderExposesAServicesMap(): void
    {
        // services() must be a static array map (the DSL loader rejects anything else).
        self::assertIsArray(TenancyServiceProvider::services());
    }

    public function testProviderIsRegisteredForActivation(): void
    {
        $enabled = require dirname(__DIR__, 3) . '/config/extensions.php';
        self::assertContains(
            'Thallo\\Tenancy\\TenancyServiceProvider',
            $enabled['enabled'] ?? [],
            'the tenancy pack provider must be in config/extensions.php enabled[]',
        );
    }
}
