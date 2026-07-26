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

    public function testProviderIsRegisteredAsAnAppModule(): void
    {
        // Modules-not-extensions: thallo-tenancy is an app-integrated module, registered in
        // config/serviceproviders.php (not the extension activation list in extensions.php).
        $enabled = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        self::assertContains(
            'Thallo\\Tenancy\\TenancyServiceProvider',
            $enabled['enabled'] ?? [],
            'the tenancy module provider must be in config/serviceproviders.php enabled[]',
        );
    }
}
