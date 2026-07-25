<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Content\Media\TenantBlobRouteMiddlewareProvider;
use Glueful\Uploader\Contracts\BlobRouteAction;
use PHPUnit\Framework\TestCase;

final class BlobResolutionTest extends TestCase
{
    public function testBlobActionsReceiveTheCorrectResolutionProfiles(): void
    {
        $provider = new TenantBlobRouteMiddlewareProvider();

        self::assertSame(['tenant_profile:public,soft'], $provider->middlewareFor(BlobRouteAction::VIEW));
        // `auth` MUST precede the admin tenant profile: with UPLOADS_ACCESS=public the framework
        // contributes no auth middleware of its own to these routes, and the admin profile's
        // membership check reads the `auth.user.uuid` attribute auth populates — without it every
        // upload 403'd ("Access to this tenant is denied") regardless of the bearer sent.
        $adminActions = [
            BlobRouteAction::UPLOAD,
            BlobRouteAction::INFO,
            BlobRouteAction::DELETE,
            BlobRouteAction::SIGN,
        ];
        foreach ($adminActions as $action) {
            self::assertSame(['auth', 'tenant_profile:admin'], $provider->middlewareFor($action));
        }
    }
}
