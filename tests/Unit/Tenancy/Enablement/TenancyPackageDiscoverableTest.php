<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\PackageManifest;

final class TenancyPackageDiscoverableTest extends AppTestCase
{
    public function testGluefulTenancyIsADevCandidate(): void
    {
        $candidates = (new PackageManifest($this->appContext()))->getCandidates();

        self::assertArrayHasKey('glueful/tenancy', $candidates);
        self::assertSame(
            'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider',
            $candidates['glueful/tenancy']->provider,
        );
    }

    public function testGluefulTenancyRequestEnforcementIsNotEnabledByDefault(): void
    {
        $extensions = require dirname(__DIR__, 4) . '/config/extensions.php';

        self::assertNotContains(
            'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider',
            $extensions['enabled'],
        );
    }

    public function testGluefulTenancyIsAProductionDependency(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('^2.0.0', $composer['require']['glueful/tenancy'] ?? null);
        self::assertArrayNotHasKey('glueful/tenancy', $composer['require-dev'] ?? []);
    }
}
