<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Thallo\Core\Tests\Support\AppTestCase;

use function config;

/**
 * Thallo's own configuration ships as DEFAULTS from core/config, merged by the core provider;
 * the root config/ holds only overrides. (tenancy.php and i18n.php stay root files: the
 * framework's tenancy and i18n providers read them before Thallo's provider registers.)
 */
final class CoreConfigDefaultsTest extends AppTestCase
{
    public function testProductConfigResolvesFromCoreDefaults(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['thallo', 'forms', 'signup', 'theme', 'import_export'] as $name) {
            self::assertFileExists("$root/core/config/$name.php");
            self::assertFileDoesNotExist("$root/config/$name.php", "$name.php is a core default now");
        }
        self::assertSame('/v1/admin', config($this->appContext(), 'thallo.admin.api_base'));
        self::assertIsArray(config($this->appContext(), 'thallo.capabilities'));
    }

    public function testARootOverrideStillWinsKeyByKeyOverTheCoreDefaults(): void
    {
        // A root/overlay file (here the suite's config/testing/thallo.php) overrides one key and
        // leaves every other key to the core defaults.
        $app = self::bootAppWithConfigOverride('thallo', ['admin' => ['api_base' => '/v1/custom-admin']]);
        try {
            self::assertSame('/v1/custom-admin', config($app, 'thallo.admin.api_base'));
            self::assertIsArray(config($app, 'thallo.capabilities'), 'untouched keys come from core/config');
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }
}
