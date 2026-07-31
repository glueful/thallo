<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Settings\SettingsStore;
use App\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Account\Settings\AccountSettingsStore;

/**
 * Under tenancy enforcement the account redirect settings are WORKSPACE-OWNED: each workspace reads
 * only its own configured targets, and one workspace's value never leaks into another's resolution
 * (public-account-surface plan Task 3, tenant posture). Self-skips when the Postgres retrofit engine
 * is unavailable, like the rest of the tenancy acceptance suite.
 */
final class AccountRedirectTenancyTest extends RetrofittedTenantTestCase
{
    protected static function seedAdditionalTenants(): bool
    {
        return true;
    }

    public function testConfiguredRedirectsAreIsolatedPerWorkspace(): void
    {
        if (self::$onApp === null) {
            self::markTestSkipped('tenancy retrofit engine unavailable');
        }

        // Store a DIFFERENT post-login redirect in each workspace.
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $this->container()->get(AccountSettingsStore::class)->saveRedirects('/account/a-home', null);
        });
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            $this->container()->get(AccountSettingsStore::class)->saveRedirects('/account/b-home', null);
        });

        // Each workspace resolves ONLY its own value (clearCache forces a scoped DB read, not a memo
        // hit from the other context).
        $a = $this->runAsTenant(self::$tenantAUuid, function (): ?string {
            $this->container()->get(SettingsStore::class)->clearCache();

            return $this->container()->get(AccountSettingsStore::class)->afterLogin();
        });
        $b = $this->runAsTenant(self::$tenantBUuid, function (): ?string {
            $this->container()->get(SettingsStore::class)->clearCache();

            return $this->container()->get(AccountSettingsStore::class)->afterLogin();
        });

        self::assertSame('/account/a-home', $a, 'workspace A reads its own redirect');
        self::assertSame('/account/b-home', $b, 'workspace B reads its own — no cross-tenant leak');
    }
}
