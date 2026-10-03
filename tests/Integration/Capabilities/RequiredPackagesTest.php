<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Capabilities\RequiredPackages;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Required packages have a mandatory minimum (spec §7.6): core declares the packages it can't run
 * without, an operator may add more in configuration, and no configuration removes core's.
 */
final class RequiredPackagesTest extends AppTestCase
{
    private const AEGIS = 'glueful/aegis';
    private const USERS = 'glueful/users';

    private function withRequired(array $packages): RequiredPackages
    {
        $context = self::bootAppWithConfigOverride('thallo', ['required_packages' => $packages]);
        return $context->getContainer()->get(RequiredPackages::class);
    }

    public function testConfigurationAddsARequiredPackage(): void
    {
        $required = $this->withRequired(['glueful/media']);
        self::assertTrue($required->isRequired('glueful/media'));
        self::assertArrayHasKey('glueful/media', $required->all());
        self::assertTrue($required->isRequired(self::AEGIS));
    }

    public function testConfigurationCannotRemoveAegisOrUsers(): void
    {
        foreach ([[], ['glueful/media']] as $configured) {
            $required = $this->withRequired($configured);
            self::assertTrue($required->isRequired(self::AEGIS), json_encode($configured));
            self::assertTrue($required->isRequired(self::USERS), json_encode($configured));
        }
    }

    public function testAnAddedRequiredPackageIsRefusedByTheGenericSwitch(): void
    {
        $context = self::bootAppWithConfigOverride('thallo', ['required_packages' => ['glueful/media']]);
        $policy = $context->getContainer()->get(FeatureManagementPolicy::class);
        self::assertSame('required', $policy->managementOf('glueful/media')['class']);
        self::assertArrayHasKey('Glueful\\Extensions\\Media\\MediaServiceProvider', $policy->protectedProviders());
    }
}
