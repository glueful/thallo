<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Extensions\ProtectedProviders;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SpySchemaExecutor;
use Thallo\Core\Tests\Support\TestableExtensionAdminController;

/**
 * One management policy decides who switches each package: Thallo's required packages and the
 * engines a feature manages are protected in `extensions.protected` without the operator writing
 * anything, so the admin toggle, the framework CLI and the protected migration lane all read the
 * same answer; an operator's own entry still wins.
 */
final class FeatureManagementPolicyTest extends AppTestCase
{
    private const COMMERCE = 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider';
    private const AEGIS = 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider';

    private function controller(): TestableExtensionAdminController
    {
        $controller = new TestableExtensionAdminController($this->appContext());
        $controller->executor = new SpySchemaExecutor();
        return $controller;
    }

    /** @return list<array<string, mixed>> */
    private function installedRows(): array
    {
        $json = json_decode((string) $this->controller()->index()->getContent(), true);
        return $json['data']['extensions'] ?? [];
    }

    public function testManagedAndRequiredProvidersAreProtectedWithoutTheOperatorsConfig(): void
    {
        $protected = (array) config($this->appContext(), 'extensions.protected', []);
        self::assertArrayHasKey(self::COMMERCE, $protected);
        self::assertArrayHasKey('Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider', $protected);
        self::assertArrayHasKey(self::AEGIS, $protected);
        self::assertArrayHasKey('Glueful\\Extensions\\Users\\UsersServiceProvider', $protected);
        self::assertArrayHasKey('Glueful\\Extensions\\Tenancy\\TenancyServiceProvider', $protected);
        self::assertStringContainsString(
            'thallo:capabilities:enable thallo.commerce',
            $protected[self::COMMERCE]['reason'],
        );
        self::assertSame('Required by Thallo.', $protected[self::AEGIS]['reason']);
        $media = 'Glueful\\Extensions\\Media\\MediaServiceProvider';
        self::assertArrayNotHasKey($media, $protected, 'an independent package stays switchable');
    }

    public function testAnOperatorProtectedEntryWins(): void
    {
        $ctx = self::bootAppWithConfigOverride('extensions', ['protected' => [
            self::COMMERCE => ['reason' => 'Ours.', 'managed_by' => 'ops'],
        ]]);
        self::assertStringStartsWith('Ours.', (string) ProtectedProviders::refusalFor($ctx, self::COMMERCE));
        self::assertNotNull(ProtectedProviders::refusalFor($ctx, self::AEGIS), 'the rest of the policy stays');
    }

    public function testTheAdminToggleRefusesManagedAndRequiredPackages(): void
    {
        $controller = $this->controller();
        foreach (['glueful/commerce', 'glueful/aegis'] as $package) {
            $request = $this->jsonRequest('POST', '/v1/admin/extensions/disable', ['name' => $package]);
            $response = $controller->disable($request);
            self::assertSame(409, $response->getStatusCode(), $package);
        }
        self::assertSame([], $controller->executor->calls, 'refused before the executor');
    }

    public function testInstalledReportsWhoManagesEachPackage(): void
    {
        $byName = array_column($this->installedRows(), null, 'name');
        self::assertSame('managed', $byName['glueful/commerce']['management']['class']);
        self::assertSame('thallo.commerce', $byName['glueful/commerce']['management']['capability']);
        self::assertSame(
            'php glueful thallo:capabilities:enable thallo.commerce',
            $byName['glueful/commerce']['cli_command'],
        );
        self::assertSame('required', $byName['glueful/aegis']['management']['class']);
        self::assertNull($byName['glueful/aegis']['cli_command']);
        self::assertSame('independent', $byName['glueful/media']['management']['class']);
        self::assertNull($byName['glueful/media']['management']['reason']);
    }

    public function testThePolicyClassifiesEveryAuditedPackage(): void
    {
        $policy = $this->container()->get(FeatureManagementPolicy::class);
        foreach (['glueful/aegis', 'glueful/users'] as $package) {
            self::assertSame('required', $policy->managementOf($package)['class'], $package);
        }
        foreach (['glueful/commerce', 'glueful/subscriptions', 'glueful/tenancy'] as $package) {
            self::assertSame('managed', $policy->managementOf($package)['class'], $package);
        }
        $independent = ['glueful/i18n', 'glueful/audit', 'glueful/media', 'glueful/email-notification',
            'glueful/import-export', 'glueful/payvia', 'glueful/meilisearch', 'acme/unknown'];
        foreach ($independent as $package) {
            self::assertSame('independent', $policy->managementOf($package)['class'], $package);
        }
        $tenancy = $policy->managementOf('glueful/tenancy');
        self::assertSame('thallo.tenancy', $tenancy['capability'], 'Workspaces is its own flow');
        self::assertSame('/settings/workspaces', $policy->managementOf('glueful/tenancy')['link']);
    }

    public function testMigrateProtectedAcceptsAManagedEngine(): void
    {
        $op = $this->container()->get(ExtensionSchemaExecutor::class)->migrateProtected('glueful/commerce', 'test');
        self::assertNotSame(ExtensionOperation::STATUS_FAILED, $op->status);
    }
}
