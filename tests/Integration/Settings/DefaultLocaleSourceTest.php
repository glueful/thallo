<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Settings;

use Thallo\Core\Providers\CoreServiceProvider;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Three "default locale"s could disagree: the default language (i18n_locales.is_default), the
 * default in Settings › General (its own row) and config/i18n.php, which most of the site read.
 * The default language is the one truth now: Settings › General shows and sets it, and the config
 * value is only the seed an install starts from.
 */
final class DefaultLocaleSourceTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr', 'de')");
        foreach ([['en', true, true], ['fr', false, true], ['de', false, false]] as [$code, $default, $enabled]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => $enabled,
                'is_default' => $default,
                'fallback_locale' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr', 'de')");
        $this->connection()->getPDO()->exec("DELETE FROM settings WHERE key = 'default_locale'");
        parent::tearDown();
    }

    private function settings(): GeneralSettings
    {
        return $this->container()->get(GeneralSettings::class);
    }

    public function testSettingsShowsTheDefaultLanguage(): void
    {
        self::assertSame('en', $this->settings()->defaultLocale());
    }

    public function testSavingTheDefaultInSettingsChangesTheDefaultLanguage(): void
    {
        $this->settings()->save(['default_locale' => 'fr']);

        $default = $this->connection()->table('i18n_locales')->where('is_default', '=', true)->get();
        self::assertSame(['fr'], array_column($default, 'code'));
        self::assertSame('fr', $this->settings()->defaultLocale());
        self::assertNull(
            $this->connection()->table('settings')->where('key', '=', 'default_locale')->first(),
            'no second copy is stored',
        );
    }

    public function testADisabledOrUnknownLanguageCannotBecomeTheDefault(): void
    {
        foreach (['de', 'xx'] as $code) {
            try {
                $this->settings()->save(['default_locale' => $code]);
                self::fail("{$code} was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($code, $e->getMessage());
            }
        }
        self::assertSame('en', $this->settings()->defaultLocale());
    }

    public function testTheSiteReadsTheDefaultLanguageNotTheConfigSeed(): void
    {
        $this->settings()->save(['default_locale' => 'fr']);

        self::assertSame('fr', CoreServiceProvider::storedDefaultLocale($this->container()));
    }
}
