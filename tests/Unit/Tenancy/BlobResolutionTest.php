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
        $adminActions = [
            BlobRouteAction::UPLOAD,
            BlobRouteAction::INFO,
            BlobRouteAction::DELETE,
            BlobRouteAction::SIGN,
        ];
        foreach ($adminActions as $action) {
            self::assertSame(['tenant_profile:admin'], $provider->middlewareFor($action));
        }
    }
}
