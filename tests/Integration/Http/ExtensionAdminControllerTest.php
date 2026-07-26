<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\ExtensionAdminController;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Regression: enable/disable read the extension name from the JSON request body. Symfony leaves
 * $request->request empty for an application/json body, so the previous `$request->request->get`
 * yielded an empty name → "No installed extension named ''" even for an installed extension.
 *
 * The unknown-name path returns 404 BEFORE any config write, so these assertions never mutate
 * config/extensions.php.
 */
final class ExtensionAdminControllerTest extends AppTestCase
{
    /** @param array<string,mixed> $body */
    private function jsonPost(array $body): Request
    {
        return Request::create(
            '/v1/admin/extensions/disable',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    public function testDisableReadsNameFromJsonBody(): void
    {
        $controller = new ExtensionAdminController($this->appContext());
        $resp = $controller->disable($this->jsonPost(['name' => 'glueful/definitely-not-installed']));

        self::assertSame(404, $resp->getStatusCode());
        // The name is now read from the JSON body, so it appears in the 404 message (not empty).
        self::assertStringContainsString('glueful/definitely-not-installed', (string) $resp->getContent());
    }

    public function testEnableReadsNameFromJsonBody(): void
    {
        $controller = new ExtensionAdminController($this->appContext());
        $resp = $controller->enable($this->jsonPost(['name' => 'glueful/definitely-not-installed']));

        self::assertSame(404, $resp->getStatusCode());
        self::assertStringContainsString('glueful/definitely-not-installed', (string) $resp->getContent());
    }

    public function testToggleRefusesTheProtectedTenancyProvider(): void
    {
        // glueful/tenancy's enforcement provider is listed in extensions.protected: its
        // activation belongs to the workspaces enablement flow, so BOTH generic toggle
        // directions must 409 before touching config/extensions.php or the cache.
        $controller = new ExtensionAdminController($this->appContext());

        foreach (['enable', 'disable'] as $action) {
            $resp = $controller->{$action}($this->jsonPost(['name' => 'glueful/tenancy']));
            self::assertSame(409, $resp->getStatusCode(), "{$action} must refuse the protected provider");
            self::assertStringContainsString('tenancy enablement flow', (string) $resp->getContent());
        }
    }
}
