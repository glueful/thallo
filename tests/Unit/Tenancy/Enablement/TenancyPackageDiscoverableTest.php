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
        // This asserts the CLEAN-INSTALL default: the committed config/extensions.php must not
        // ship with the enforcement provider enabled. But `config/extensions.php` is also the
        // file `extensions:enable` mutates in place, so on a dogfooding workstation with tenancy
        // switched on the working copy legitimately contains the provider — that is dev state,
        // not a shipped default. `git diff` tells the two apart: skip only when the working copy
        // deviates from the committed file (CI/clean checkouts never skip, so the shipped
        // default stays enforced where it matters).
        $root = dirname(__DIR__, 4);
        $extensions = require $root . '/config/extensions.php';

        $enabled = $extensions['enabled'];
        $provider = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';

        if (in_array($provider, $enabled, true)) {
            exec(
                'git -C ' . escapeshellarg($root) . ' diff --quiet -- config/extensions.php 2>/dev/null',
                $output,
                $dirty,
            );
            if ($dirty !== 0) {
                self::markTestSkipped(
                    'config/extensions.php is locally modified (tenancy dev-enabled via '
                    . 'extensions:enable); the clean-install default is asserted on clean checkouts.',
                );
            }
        }

        self::assertNotContains($provider, $enabled);
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
