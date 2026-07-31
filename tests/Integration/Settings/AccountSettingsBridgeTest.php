<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Thallo\Account\Settings\AccountSettingsStore;

/**
 * The app's bridge behind thallo-account's redirect-settings contract (public-account-surface plan
 * Task 3): the two overrides are ordinary {@see SettingsStore} rows, `saveRedirects` writes the pair
 * together, and a null argument DELETES the row (never blanks it) so the fixed default shows through.
 * Tenant scoping is SettingsStore's concern (see {@see AccountRedirectTenancyTest}); this pins the
 * bridge's own read/write/clear contract.
 */
final class AccountSettingsBridgeTest extends AppTestCase
{
    private function store(): AccountSettingsStore
    {
        return $this->container()->get(AccountSettingsStore::class);
    }

    private function settings(): SettingsStore
    {
        return $this->container()->get(SettingsStore::class);
    }

    public function testSaveRedirectsPersistsBothAndReadsThemBack(): void
    {
        $this->store()->saveRedirects('/account/home', '/goodbye');
        $this->settings()->clearCache();

        self::assertSame('/account/home', $this->store()->afterLogin());
        self::assertSame('/goodbye', $this->store()->afterLogout());
    }

    public function testNoOverrideReadsAsNull(): void
    {
        // A fresh install has stored nothing, so both accessors report "no override".
        self::assertNull($this->store()->afterLogin());
        self::assertNull($this->store()->afterLogout());
    }

    public function testANullArgumentDeletesTheRowSoTheDefaultShowsThrough(): void
    {
        $this->store()->saveRedirects('/account/home', '/goodbye');
        $this->settings()->clearCache();

        // Clear only the post-login override; the post-logout one must survive.
        $this->store()->saveRedirects(null, '/goodbye');
        $this->settings()->clearCache();

        self::assertNull($this->store()->afterLogin(), 'a null argument means "no override"');
        self::assertNull(
            $this->connection()->table('settings')->where('key', '=', 'account.redirect.after_login')->first(),
            'the row is DELETED, not blanked',
        );
        self::assertSame('/goodbye', $this->store()->afterLogout(), 'the untouched override is preserved');
    }
}
