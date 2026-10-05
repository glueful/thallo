<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Render\Http\Controllers\RenderAdminController;

/**
 * A stage's freshness check reads its own workspace (block typeface plan Task 11): another
 * workspace's appearance change never alters it, and its own does.
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class AppearanceFingerprintTenancyTest extends RetrofittedTenantTestCase
{
    private function fingerprint(string $tenant): string
    {
        return (string) $this->runAsTenant($tenant, function (): string {
            $this->container()->get(SettingsStore::class)->clearCache();
            $res = $this->container()->get(RenderAdminController::class)->appearanceFingerprint();
            return (string) (json_decode((string) $res->getContent(), true)['data']['appearance_fingerprint'] ?? '');
        });
    }

    private function accent(string $tenant, string $accent): void
    {
        $this->runAsTenant(
            $tenant,
            fn () => $this->container()->get(SettingsStore::class)->putMany(['theme_accent' => $accent]),
        );
    }

    public function testAnotherWorkspacesChangeLeavesItAlone(): void
    {
        $this->accent(self::$tenantAUuid, 'blue');
        $this->accent(self::$tenantBUuid, 'blue');
        $a = $this->fingerprint(self::$tenantAUuid);
        self::assertNotSame('', $a);

        $this->accent(self::$tenantBUuid, 'rose');
        self::assertSame($a, $this->fingerprint(self::$tenantAUuid));
        self::assertNotSame($a, $this->fingerprint(self::$tenantBUuid));

        $this->accent(self::$tenantAUuid, 'emerald');
        self::assertNotSame($a, $this->fingerprint(self::$tenantAUuid));
    }
}
